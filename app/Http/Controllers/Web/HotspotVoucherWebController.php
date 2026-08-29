<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Imports\HotspotVouchersImport;
use App\Models\Device;
use App\Models\HotspotVoucher;
use App\Services\MikrotikService;
use App\Services\PmbStudentService;
use App\Services\PmbVoucherSync;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Voucher WiFi mahasiswa: kelola daftar NIM + password di database CIMS,
 * lalu push ke /ip/hotspot/user pada router MikroTik.
 *
 * Alur sengaja dua tahap (simpan dulu → push manual) supaya file Excel yang
 * salah tidak langsung mengotori konfigurasi router.
 */
class HotspotVoucherWebController extends Controller
{
    /** Maksimal voucher yang dikirim ke router dalam satu klik push. */
    protected const PUSH_CHUNK = 300;

    /** Batas aman jumlah kartu voucher per file PDF. */
    protected const PRINT_LIMIT = 2000;

    public function __construct(protected MikrotikService $mikrotik)
    {
    }

    /**
     * Router tujuan: dari query string, HOTSPOT_ROUTER_HOST, MIKROTIK_HOST,
     * atau router MikroTik pertama di inventaris.
     *
     * HOTSPOT_ROUTER_HOST didahulukan karena router yang menjalankan hotspot
     * biasanya bukan router uplink yang dipakai monitoring — voucher yang masuk
     * ke router salah akan ditolak dengan "username doesn't exist".
     */
    protected function resolveHost(Request $request): ?string
    {
        $host = $request->input('router_host') ?? $request->query('host');

        return $host
            ?: (config('services.hotspot.router_host')
                ?: (config('services.mikrotik.host') ?: $this->firstInventoryRouterIp()));
    }

    protected function firstInventoryRouterIp(): ?string
    {
        return $this->mikrotikRouters()[0]['ip'] ?? null;
    }

    /**
     * Router MikroTik yang ada di Device Inventory, untuk dropdown pemilih router.
     */
    protected function mikrotikRouters(): array
    {
        return Device::with('building')
            ->whereNotNull('ip_address')
            ->where(function ($query) {
                $query->whereHas('vendor', fn ($q) => $q->where('name', 'like', '%mikrotik%'))
                    ->orWhereHas('category', fn ($q) => $q->where('name', 'like', '%mikrotik%'));
            })
            ->get()
            ->map(fn ($device) => [
                'id' => $device->id,
                'name' => $device->name,
                'ip' => $device->ip_address,
                'location' => $device->building?->name,
            ])
            ->unique('ip')
            ->values()
            ->all();
    }

    /**
     * Halaman utama: tabel voucher + statistik. Data yang butuh round-trip ke
     * router dikirim sebagai deferred prop agar halaman langsung terbuka.
     */
    public function index(Request $request)
    {
        $host = $this->resolveHost($request);

        $filters = [
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'profile' => $request->query('profile'),
            'batch' => $request->query('batch'),
        ];

        $query = HotspotVoucher::query()
            ->where('router_host', $host)
            ->search($filters['search'])
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['profile'], fn ($q, $profile) => $q->where('profile', $profile))
            ->when($filters['batch'], fn ($q, $batch) => $q->where('batch_label', $batch))
            ->orderBy('nim');

        $vouchers = $query->paginate(50)->withQueryString();

        $counts = HotspotVoucher::where('router_host', $host)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Satu probe router per kunjungan, dibagi ke semua deferred prop.
        $probe = null;
        $live = function () use (&$probe, $host) {
            return $probe ??= [
                'connection' => $this->mikrotik->testConnection($host),
                'profiles' => $this->mikrotik->getHotspotProfiles($host),
                'servers' => $this->mikrotik->getHotspotServers($host),
            ];
        };

        return Inertia::render('Hotspot/Vouchers', [
            'vouchers' => $vouchers,
            'filters' => $filters,
            'routerHost' => $host,
            'routers' => $this->mikrotikRouters(),
            'hotspot' => $this->hotspotIdentity(),
            'batches' => HotspotVoucher::where('router_host', $host)
                ->whereNotNull('batch_label')
                ->distinct()
                ->orderBy('batch_label')
                ->pluck('batch_label'),
            'stats' => [
                'total' => (int) $counts->sum(),
                'pending' => (int) ($counts['pending'] ?? 0),
                'synced' => (int) ($counts['synced'] ?? 0),
                'failed' => (int) ($counts['failed'] ?? 0),
                'disabled' => (int) ($counts['disabled'] ?? 0),
            ],
            'connection' => Inertia::defer(fn () => $live()['connection']),
            'hotspotProfiles' => Inertia::defer(fn () => $live()['profiles']),
            'hotspotServers' => Inertia::defer(fn () => $live()['servers']),
        ]);
    }

    /**
     * Tambah satu voucher secara manual dari form web.
     */
    public function store(Request $request)
    {
        $host = $this->resolveHost($request);
        $data = $this->validateVoucher($request, $host);

        // Voucher baru ikut profile kampus (HOTSPOT_DEFAULT_PROFILE) supaya
        // limit bandwidth & shared-user-nya benar, bukan profile "default"
        // milik router yang biasanya tanpa batas.
        $data['profile'] = filled($data['profile'] ?? null)
            ? $data['profile']
            : $this->defaultProfile();

        HotspotVoucher::create($data + [
            'router_host' => $host,
            'status' => HotspotVoucher::STATUS_PENDING,
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('success', "Voucher untuk NIM {$data['nim']} tersimpan sebagai pending. Klik \"Push ke Router\" untuk mengaktifkannya.");
    }

    /** User profile RouterOS bawaan untuk voucher yang tidak menyebut profile. */
    protected function defaultProfile(): ?string
    {
        return config('services.hotspot.default_profile') ?: null;
    }

    /**
     * Identitas hotspot kampus apa adanya dari .env (lewat config/services.php).
     *
     * Dikirim ke halaman voucher supaya SSID, portal login, router, dan profile
     * yang dipakai aplikasi tidak pernah ditulis ulang di komponen React —
     * cukup ubah HOTSPOT_* di .env, semua tampilan & PDF ikut berubah.
     *
     * @return array<string,string|bool|null>
     */
    protected function hotspotIdentity(): array
    {
        return [
            'ssid' => config('services.hotspot.ssid') ?: null,
            'login_url' => config('services.hotspot.login_url') ?: null,
            'router_host' => config('services.hotspot.router_host') ?: null,
            'default_profile' => $this->defaultProfile(),

            // Tombol "Tarik dari SISKA" hanya berguna bila API_PMB & tokennya
            // sudah diisi; tanpa ini tombolnya cuma memunculkan pesan gagal.
            'pmb_configured' => app(PmbStudentService::class)->configured(),
        ];
    }

    /**
     * Ubah data voucher. Perubahan kredensial otomatis menandai perlu push ulang.
     */
    public function update(Request $request, int $id)
    {
        $voucher = HotspotVoucher::findOrFail($id);
        $data = $this->validateVoucher($request, $voucher->router_host, $voucher->id);

        $voucher->fill($data);

        if ($voucher->isDirty(['nim', 'password', 'profile', 'server', 'limit_uptime'])) {
            $voucher->status = HotspotVoucher::STATUS_PENDING;
            $voucher->last_error = null;
        }

        $voucher->save();

        return back()->with('success', "Voucher NIM {$voucher->nim} diperbarui.");
    }

    /**
     * Hapus voucher dari CIMS sekaligus dari router bila sudah pernah dipush.
     */
    public function destroy(int $id)
    {
        $voucher = HotspotVoucher::findOrFail($id);
        $warning = null;

        if ($voucher->mikrotik_id) {
            try {
                $this->mikrotik->deleteHotspotUser($voucher->router_host, $voucher->mikrotik_id);
            } catch (\Throwable $e) {
                Log::warning("Hapus hotspot user {$voucher->nim} di router gagal: {$e->getMessage()}");
                $warning = ' Namun entri di router gagal dihapus: ' . Str::limit($e->getMessage(), 120);
            }
        }

        $nim = $voucher->nim;
        $voucher->delete();

        return back()->with($warning ? 'error' : 'success', "Voucher NIM {$nim} dihapus dari CIMS." . $warning);
    }

    /**
     * Aturan validasi form voucher (dipakai store & update).
     *
     * @return array<string,mixed>
     */
    protected function validateVoucher(Request $request, ?string $host, ?int $ignoreId = null): array
    {
        $validated = $request->validate([
            'nim' => [
                'required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('hotspot_vouchers', 'nim')
                    ->where(fn ($q) => $q->where('router_host', $host))
                    ->ignore($ignoreId),
            ],
            'student_name' => ['nullable', 'string', 'max:255'],
            'faculty' => ['nullable', 'string', 'max:255'],
            'program' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:128'],
            'profile' => ['nullable', 'string', 'max:64'],
            'server' => ['nullable', 'string', 'max:64'],
            'limit_uptime' => ['nullable', 'string', 'max:32'],
            'valid_until' => ['nullable', 'date'],
            'batch_label' => ['nullable', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:255'],
        ], [
            'nim.regex' => 'NIM hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda hubung.',
            'nim.unique' => 'NIM ini sudah punya voucher di router tersebut.',
        ]);

        // Kebijakan kampus: password voucher sama dengan NIM bila tidak diisi.
        $validated['password'] = filled($validated['password'] ?? null)
            ? $validated['password']
            : $validated['nim'];

        return $validated;
    }

    /**
     * Push voucher terpilih (atau semua yang pending/gagal) ke router.
     * Dibatasi PUSH_CHUNK per klik supaya request tidak timeout untuk ribuan NIM.
     */
    public function push(Request $request)
    {
        $host = $this->resolveHost($request);

        $request->validate([
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
        ]);

        $ids = $request->input('ids', []);

        $connection = $this->mikrotik->testConnection($host);

        if (!$connection['success']) {
            return back()->with('error', "Router {$host} tidak bisa dihubungi: " . Str::limit($connection['error'] ?? 'unknown', 150));
        }

        $base = HotspotVoucher::where('router_host', $host)
            ->when(!empty($ids), fn ($q) => $q->whereIn('id', $ids))
            ->when(empty($ids), fn ($q) => $q->whereIn('status', [
                HotspotVoucher::STATUS_PENDING,
                HotspotVoucher::STATUS_FAILED,
            ]));

        $outstanding = (clone $base)->count();
        $vouchers = $base->orderBy('nim')->limit(self::PUSH_CHUNK)->get();

        if ($vouchers->isEmpty()) {
            return back()->with('error', 'Tidak ada voucher yang perlu dipush ke router.');
        }

        [$ok, $failed, $firstError] = $this->pushMany($vouchers);

        $remaining = max(0, $outstanding - $vouchers->count());
        $message = "Push ke {$host}: {$ok} voucher berhasil";

        if ($failed > 0) {
            $message .= ", {$failed} gagal (" . Str::limit((string) $firstError, 120) . ')';
        }

        if ($remaining > 0) {
            $message .= ". Sisa {$remaining} voucher — klik Push sekali lagi untuk melanjutkan.";
        }

        return back()->with($failed > 0 ? 'error' : 'success', $message . '.');
    }

    /**
     * Push satu voucher (tombol per baris).
     */
    public function pushOne(int $id)
    {
        $voucher = HotspotVoucher::findOrFail($id);

        [$ok, $failed, $firstError] = $this->pushMany(collect([$voucher]));

        return $ok === 1
            ? back()->with('success', "Voucher NIM {$voucher->nim} aktif di router {$voucher->router_host}.")
            : back()->with('error', "Push NIM {$voucher->nim} gagal: " . Str::limit((string) $firstError, 150));
    }

    /**
     * Kirim sekumpulan voucher ke router, catat hasil per baris.
     *
     * @param \Illuminate\Support\Collection<int,HotspotVoucher> $vouchers
     * @return array{0:int,1:int,2:string|null}
     */
    protected function pushMany($vouchers): array
    {
        $ok = 0;
        $failed = 0;
        $firstError = null;

        foreach ($vouchers as $voucher) {
            try {
                $routerId = $this->mikrotik->upsertHotspotUser(
                    $voucher->router_host,
                    $voucher->toRouterAttributes()
                );

                $voucher->forceFill([
                    'mikrotik_id' => $routerId !== '' ? $routerId : $voucher->mikrotik_id,
                    'status' => HotspotVoucher::STATUS_SYNCED,
                    'last_error' => null,
                    'synced_at' => now(),
                ])->save();

                $ok++;
            } catch (\Throwable $e) {
                Log::warning("Push voucher {$voucher->nim} ke {$voucher->router_host} gagal: {$e->getMessage()}");

                $voucher->forceFill([
                    'status' => HotspotVoucher::STATUS_FAILED,
                    'last_error' => Str::limit($e->getMessage(), 500),
                ])->save();

                $failed++;
                $firstError ??= $e->getMessage();
            }
        }

        return [$ok, $failed, $firstError];
    }

    /**
     * Blokir / buka blokir voucher di router tanpa menghapus datanya.
     */
    public function toggle(int $id)
    {
        $voucher = HotspotVoucher::findOrFail($id);

        if (!$voucher->mikrotik_id) {
            return back()->with('error', "Voucher NIM {$voucher->nim} belum pernah dipush ke router.");
        }

        $disable = $voucher->status !== HotspotVoucher::STATUS_DISABLED;

        try {
            $this->mikrotik->setHotspotUserDisabled($voucher->router_host, $voucher->mikrotik_id, $disable);
        } catch (\Throwable $e) {
            return back()->with('error', "Gagal mengubah status NIM {$voucher->nim} di router: " . Str::limit($e->getMessage(), 150));
        }

        $voucher->forceFill([
            'status' => $disable ? HotspotVoucher::STATUS_DISABLED : HotspotVoucher::STATUS_SYNCED,
            'last_error' => null,
        ])->save();

        return back()->with('success', "Voucher NIM {$voucher->nim} " . ($disable ? 'diblokir' : 'diaktifkan kembali') . '.');
    }

    /**
     * Putuskan sesi hotspot yang sedang aktif untuk satu NIM.
     */
    public function kick(int $id)
    {
        $voucher = HotspotVoucher::findOrFail($id);

        try {
            $kicked = $this->mikrotik->kickHotspotActive($voucher->router_host, $voucher->nim);
        } catch (\Throwable $e) {
            return back()->with('error', "Gagal memutus sesi NIM {$voucher->nim}: " . Str::limit($e->getMessage(), 150));
        }

        return back()->with('success', $kicked > 0
            ? "{$kicked} sesi aktif NIM {$voucher->nim} diputus."
            : "Tidak ada sesi aktif untuk NIM {$voucher->nim}.");
    }

    /**
     * Import daftar mahasiswa dari Excel/CSV menjadi voucher pending.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:20480'],
            'profile' => ['nullable', 'string', 'max:64'],
            'server' => ['nullable', 'string', 'max:64'],
            'batch_label' => ['nullable', 'string', 'max:64'],
            'valid_until' => ['nullable', 'date'],
        ]);

        $host = $this->resolveHost($request);

        if (blank($host)) {
            return back()->with('error', 'Router tujuan belum ditentukan. Pilih router MikroTik terlebih dahulu.');
        }

        $file = $request->file('file');
        $relativePath = $file->storeAs(
            'imports',
            'voucher_hotspot_' . time() . '.' . $file->getClientOriginalExtension(),
            'local'
        );

        $fullPath = Storage::disk('local')->path($relativePath);

        $import = new HotspotVouchersImport(
            routerHost: $host,
            defaultProfile: $request->input('profile') ?: $this->defaultProfile(),
            defaultServer: $request->input('server'),
            batchLabel: $request->input('batch_label'),
            userId: $request->user()?->id,
            validUntil: $request->input('valid_until'),
        );

        try {
            Excel::import($import, $fullPath);
        } catch (\Throwable $e) {
            Log::error('Import voucher hotspot gagal: ' . $e->getMessage());

            return back()->with('error', 'Gagal membaca file: ' . Str::limit($e->getMessage(), 200));
        }

        if ($import->created === 0 && $import->updated === 0) {
            return back()->with('error', 'Tidak ada NIM yang terbaca dari file. Pastikan ada kolom berjudul "NIM" (atau Username), atau NIM berada di kolom A.');
        }

        $message = "Import selesai: {$import->created} voucher baru, {$import->updated} diperbarui";

        if ($import->skipped > 0) {
            $message .= ", {$import->skipped} baris dilewati";

            if ($import->duplicates !== []) {
                $message .= ' (NIM ganda: ' . implode(', ', array_slice($import->duplicates, 0, 5)) . ')';
            }
        }

        return back()->with('success', $message . '. Semua berstatus pending — klik "Push ke Router" untuk mengaktifkan.');
    }

    /**
     * Tarik daftar mahasiswa dari API SISKA/PMB menjadi voucher pending.
     *
     * Tidak ada file yang diunggah: daftarnya diambil langsung dari SISKA, dan
     * password mahasiswa dibentuk dari tanggal lahirnya. Mahasiswa yang tanggal
     * lahirnya belum terisi di SISKA tetap dapat voucher dengan password NIM,
     * dan jumlahnya disebut di pesan hasil supaya bisa ditindaklanjuti.
     */
    public function syncPmb(Request $request, PmbVoucherSync $sync)
    {
        $request->validate([
            'prodi' => ['nullable', 'string', 'max:32'],
            'search' => ['nullable', 'string', 'max:64'],
            'profile' => ['nullable', 'string', 'max:64'],
            'server' => ['nullable', 'string', 'max:64'],
            'batch_label' => ['nullable', 'string', 'max:64'],
            'valid_until' => ['nullable', 'date'],
        ]);

        $host = $this->resolveHost($request);

        if (blank($host)) {
            return back()->with('error', 'Router tujuan belum ditentukan. Pilih router MikroTik terlebih dahulu.');
        }

        try {
            $report = $sync->run($host, [
                'prodi' => $request->input('prodi'),
                'search' => $request->input('search'),
                'profile' => $request->input('profile') ?: $this->defaultProfile(),
                'server' => $request->input('server'),
                'batch_label' => $request->input('batch_label'),
                'valid_until' => $request->input('valid_until'),
                'user_id' => $request->user()?->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Sinkronisasi voucher dari SISKA gagal: ' . $e->getMessage());

            return back()->with('error', 'Gagal menarik data dari SISKA: ' . Str::limit($e->getMessage(), 200));
        }

        if ($report['total'] === 0) {
            return back()->with('error', 'SISKA tidak mengembalikan satu pun mahasiswa untuk filter ini.');
        }

        $message = "Sinkronisasi SISKA selesai: {$report['created']} voucher baru, {$report['updated']} diperbarui";

        if ($report['by_nim'] > 0) {
            $message .= ". {$report['by_nim']} mahasiswa belum punya tanggal lahir di SISKA, jadi passwordnya "
                . 'memakai NIM (contoh: ' . implode(', ', array_slice($report['nim_samples'], 0, 3)) . ')';
        }

        if ($report['skipped'] > 0) {
            $message .= ", {$report['skipped']} NIM dilewati karena tidak bisa jadi username hotspot";
        }

        return back()->with('success', $message . '. Semua berstatus pending — klik "Push ke Router" untuk mengaktifkan.');
    }

    /**
     * Query voucher sesuai filter/pilihan yang sedang aktif di halaman.
     */
    protected function filteredQuery(Request $request, ?string $host)
    {
        $ids = array_filter(explode(',', (string) $request->query('ids')));

        return HotspotVoucher::where('router_host', $host)
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->search($request->query('search'))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('profile'), fn ($q, $profile) => $q->where('profile', $profile))
            ->when($request->query('batch'), fn ($q, $batch) => $q->where('batch_label', $batch))
            ->orderBy('nim');
    }

    /**
     * Unduh contoh format Excel/CSV untuk diisi oleh BAAK atau prodi.
     */
    public function template()
    {
        $rows = [
            ['NIM', 'Nama', 'Prodi', 'Fakultas', 'Password', 'Profile', 'Keterangan'],
            ['2101001', 'Contoh Mahasiswa A', 'Teknik Informatika', 'Teknik', '', '', 'Angkatan 2021'],
            ['2101002', 'Contoh Mahasiswa B', 'Sistem Informasi', 'Teknik', '', '', ''],
        ];

        return $this->csvDownload('template_voucher_hotspot.csv', $rows);
    }

    /**
     * Export daftar voucher (beserta password) ke CSV yang bisa dibuka Excel.
     */
    public function export(Request $request)
    {
        $host = $this->resolveHost($request);

        $rows = [['NIM', 'Nama', 'Password', 'Prodi', 'Fakultas', 'Profile', 'Router', 'Status', 'Batch', 'Berlaku Sampai', 'Terakhir Sync']];

        foreach ($this->filteredQuery($request, $host)->cursor() as $voucher) {
            $rows[] = [
                $voucher->nim,
                $voucher->student_name,
                $voucher->password,
                $voucher->program,
                $voucher->faculty,
                $voucher->profile,
                $voucher->router_host,
                strtoupper($voucher->status),
                $voucher->batch_label,
                $voucher->valid_until?->format('Y-m-d'),
                $voucher->synced_at?->toDateTimeString(),
            ];
        }

        return $this->csvDownload('voucher_hotspot_' . date('Ymd_His') . '.csv', $rows);
    }

    /**
     * Streaming CSV dengan BOM UTF-8 agar Excel tidak merusak karakter Indonesia.
     *
     * @param array<int,array<int,mixed>>|iterable $rows
     */
    protected function csvDownload(string $filename, iterable $rows)
    {
        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Cetak kartu voucher (PDF, 2 kolom × 5 baris per halaman A4).
     */
    public function printCards(Request $request)
    {
        $host = $this->resolveHost($request);

        $vouchers = $this->filteredQuery($request, $host)->limit(self::PRINT_LIMIT)->get();

        if ($vouchers->isEmpty()) {
            return back()->with('error', 'Tidak ada voucher yang cocok untuk dicetak.');
        }

        $identity = $this->hotspotIdentity();

        $pdf = Pdf::loadView('hotspot.voucher_cards', [
            'vouchers' => $vouchers,
            'ssid' => $request->query('ssid') ?: $identity['ssid'],
            'loginUrl' => $request->query('login_url') ?: $identity['login_url'],
            'institution' => config('app.name'),
            'printedAt' => now()->format('d/m/Y H:i'),
        ])->setPaper('a4', 'portrait');

        return $pdf->stream('voucher_hotspot_' . date('Ymd_His') . '.pdf');
    }

    /**
     * JSON: sesi hotspot yang sedang aktif, digabung dengan data mahasiswa.
     */
    public function activeUsers(Request $request)
    {
        $host = $this->resolveHost($request);
        $active = $this->mikrotik->getHotspotActive($host);

        $names = HotspotVoucher::where('router_host', $host)
            ->whereIn('nim', array_filter(array_column($active, 'user')))
            ->pluck('student_name', 'nim');

        $sessions = array_map(fn ($session) => $session + [
            'student_name' => $names[$session['user']] ?? null,
            'registered' => isset($names[$session['user']]),
        ], $active);

        return response()->json([
            'router_host' => $host,
            'total' => count($sessions),
            'sessions' => $sessions,
            'fetched_at' => now()->toDateTimeString(),
        ]);
    }
}
