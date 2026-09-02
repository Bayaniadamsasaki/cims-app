<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Imports\HotspotVouchersImport;
use App\Models\Device;
use App\Models\HotspotVoucher;
use App\Services\MikrotikService;
use App\Services\PmbStudentService;
use App\Services\PmbVoucherSync;
use App\Services\RadiusService;
use App\Support\VoucherRadiusApplier;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Voucher WiFi mahasiswa: kelola daftar NIM + password di database CIMS, lalu
 * terapkan ke database FreeRADIUS.
 *
 * Satu identitas berlaku di semua router hotspot kampus. Yang menjawab
 * Access-Request cuma satu server RADIUS, jadi voucher tidak lagi milik router
 * tertentu — kolom router_host tinggal catatan router yang dipakai saat baris ini
 * dibuat, bukan penentu apa pun.
 *
 * Alurnya tetap sengaja dua tahap (simpan dulu → terapkan manual) supaya file
 * Excel yang salah tidak langsung mengubah siapa yang boleh login.
 *
 * MikrotikService tetap dipakai, tapi hanya untuk yang memang cuma diketahui
 * router: sesi yang sedang berjalan dan memutusnya.
 */
class HotspotVoucherWebController extends Controller
{
    /** Batas aman jumlah kartu voucher per file PDF. */
    protected const PRINT_LIMIT = 2000;

    public function __construct(
        protected MikrotikService $mikrotik,
        protected RadiusService $radius,
        protected VoucherRadiusApplier $applier,
    ) {
    }

    /**
     * Router yang sedang dilihat: dari query string, HOTSPOT_ROUTER_HOST,
     * MIKROTIK_HOST, atau router MikroTik pertama di inventaris.
     *
     * Sejak voucher berpindah ke RADIUS, alamat ini tidak lagi menentukan siapa
     * yang boleh login — ia hanya menentukan router mana yang ditanyai soal sesi
     * aktif, dan dicatat di kolom router_host sebagai jejak.
     *
     * HOTSPOT_ROUTER_HOST tetap didahulukan karena router yang menjalankan
     * hotspot biasanya bukan router uplink yang dipakai monitoring.
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
     * Halaman utama: tabel voucher + statistik.
     *
     * Daftarnya tidak lagi disaring per router — satu voucher berlaku di seluruh
     * kampus, dan menyaringnya per alamat router hanya akan menyembunyikan baris
     * yang dibuat saat router masih memakai IP lain.
     *
     * Data yang butuh round-trip ke luar dikirim sebagai deferred prop, dan dibagi
     * dua grup: RADIUS (yang menentukan izin login) tidak boleh ikut menunggu
     * router yang mungkin sedang mati, dan sebaliknya.
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

        $vouchers = HotspotVoucher::query()
            ->search($filters['search'])
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['profile'], fn ($q, $profile) => $q->where('profile', $profile))
            ->when($filters['batch'], fn ($q, $batch) => $q->where('batch_label', $batch))
            ->orderBy('nim')
            ->paginate(50)
            ->withQueryString();

        $counts = HotspotVoucher::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Sebab nonaktifnya ikut dihitung: status 'disabled' tanpa alasan membuat
        // operator menebak apakah itu keputusannya sendiri atau hasil sinkronisasi
        // PMB — dua hal yang penanganannya berbeda.
        $disabledReasons = HotspotVoucher::query()
            ->where('status', HotspotVoucher::STATUS_DISABLED)
            ->selectRaw('disabled_reason, count(*) as total')
            ->groupBy('disabled_reason')
            ->pluck('total', 'disabled_reason')
            ->mapWithKeys(fn ($total, $reason) => [
                (blank($reason) ? 'diblokir operator' : (string) $reason) => (int) $total,
            ]);

        // Satu probe router per kunjungan, dibagi ke semua deferred prop miliknya.
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
            'batches' => HotspotVoucher::query()
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
            'disabledReasons' => $disabledReasons,

            // RADIUS lebih dulu: inilah yang menentukan mahasiswa bisa login.
            'connection' => Inertia::defer(fn () => $this->radius->health()),
            'radiusGroups' => Inertia::defer(fn () => $this->radius->groups()),

            // Router menyusul di grup terpisah, dipakai panel sesi aktif dan
            // pembanding nama profile.
            'routerConnection' => Inertia::defer(fn () => $live()['connection'], 'router'),
            'hotspotProfiles' => Inertia::defer(fn () => $live()['profiles'], 'router'),
            'hotspotServers' => Inertia::defer(fn () => $live()['servers'], 'router'),
        ]);
    }

    /**
     * Tambah satu voucher secara manual dari form web.
     */
    public function store(Request $request)
    {
        $host = $this->resolveHost($request);
        $data = $this->validateVoucher($request);

        // Voucher baru ikut paket kampus (HOTSPOT_RADIUS_DEFAULT_GROUP) supaya
        // rate limit & batas sesinya benar, bukan group "default" yang biasanya
        // tanpa policy sama sekali.
        $data['profile'] = filled($data['profile'] ?? null)
            ? $data['profile']
            : $this->defaultProfile();

        HotspotVoucher::create($data + [
            'router_host' => $host,
            'status' => HotspotVoucher::STATUS_PENDING,
            'source' => HotspotVoucher::SOURCE_MANUAL,
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('success', "Voucher untuk NIM {$data['nim']} tersimpan sebagai pending. Klik \"Terapkan ke RADIUS\" untuk mengaktifkannya.");
    }

    /**
     * Paket bawaan untuk voucher yang tidak menyebut profile.
     *
     * HOTSPOT_RADIUS_DEFAULT_GROUP didahulukan karena kolom profile sekarang
     * bermuara di radusergroup.groupname. HOTSPOT_DEFAULT_PROFILE dipertahankan
     * sebagai cadangan untuk pemasangan yang belum mengisi yang baru.
     */
    protected function defaultProfile(): ?string
    {
        return $this->radius->defaultGroup()
            ?: (config('services.hotspot.default_profile') ?: null);
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

            // Begitu pula tombol "Terapkan ke RADIUS" bila RADIUS_DB_* kosong.
            // Dipisah dari prop connection yang deferred: yang ini cuma membaca
            // config, jadi tombolnya tidak perlu menunggu jaringan.
            'radius_configured' => $this->radius->configured(),
        ];
    }

    /**
     * Ubah data voucher. Perubahan kredensial otomatis menandai perlu diterapkan
     * ulang ke RADIUS.
     */
    public function update(Request $request, int $id)
    {
        $voucher = HotspotVoucher::findOrFail($id);
        $data = $this->validateVoucher($request, $voucher->id);

        $voucher->fill($data);

        $previousNim = (string) $voucher->getOriginal('nim');
        $renamed = $voucher->isDirty('nim');

        // 'server' tidak ikut dilihat: itu nama hotspot server RouterOS, tidak
        // punya padanan di RADIUS, jadi mengubahnya tidak membuat baris jadi basi.
        if ($voucher->isDirty(['nim', 'password', 'profile', 'limit_uptime'])) {
            $voucher->status = HotspotVoucher::STATUS_PENDING;
            $voucher->last_error = null;
        }

        // NIM berganti berarti username barunya belum pernah ada di RADIUS.
        if ($renamed) {
            $voucher->synced_at = null;
        }

        $voucher->save();

        // NIM lama harus dicabut, bukan ditinggalkan: username itu sudah tidak ada
        // di CIMS, tapi password dan groupnya masih menerima login.
        $warning = '';

        if ($renamed && filled($previousNim)) {
            try {
                $this->radius->forget($previousNim);
            } catch (\Throwable $e) {
                Log::warning("Cabut NIM lama {$previousNim} dari RADIUS gagal: {$e->getMessage()}");
                $warning = " Namun NIM lama {$previousNim} gagal dicabut dari RADIUS — jalankan radius:reconcile.";
            }
        }

        return back()->with($warning === '' ? 'success' : 'error',
            "Voucher NIM {$voucher->nim} diperbarui.".$warning);
    }

    /**
     * Hapus voucher dari CIMS sekaligus mencabut izin loginnya.
     */
    public function destroy(int $id)
    {
        $voucher = HotspotVoucher::findOrFail($id);
        $nim = $voucher->nim;
        $warnings = [];

        // RADIUS lebih dulu: selama barisnya masih di radcheck, mahasiswa yang
        // vouchernya sudah hilang dari halaman ini tetap bisa login.
        try {
            $this->radius->forget($nim);
        } catch (\Throwable $e) {
            Log::warning("Cabut NIM {$nim} dari RADIUS gagal: {$e->getMessage()}");
            $warnings[] = 'entri RADIUS gagal dicabut ('.Str::limit($e->getMessage(), 100)
                .') — jalankan radius:reconcile';
        }

        // Sisa era push-ke-router: baris /ip/hotspot/user yang tertinggal dipakai
        // router sebagai database lokal, jadi ia tetap harus dibersihkan.
        if ($voucher->mikrotik_id) {
            try {
                $this->mikrotik->deleteHotspotUser($voucher->router_host, $voucher->mikrotik_id);
            } catch (\Throwable $e) {
                Log::warning("Hapus hotspot user {$nim} di router gagal: {$e->getMessage()}");
                $warnings[] = "entri lama di router {$voucher->router_host} gagal dihapus";
            }
        }

        $voucher->delete();

        return back()->with($warnings === [] ? 'success' : 'error',
            "Voucher NIM {$nim} dihapus dari CIMS."
            .($warnings === [] ? '' : ' Namun '.implode('; ', $warnings).'.'));
    }

    /**
     * Aturan validasi form voucher (dipakai store & update).
     *
     * NIM unik secara global sekarang, bukan lagi per router: satu mahasiswa cukup
     * punya satu identitas karena yang menjawab seluruh router hotspot kampus
     * hanyalah satu server RADIUS.
     *
     * @return array<string,mixed>
     */
    protected function validateVoucher(Request $request, ?int $ignoreId = null): array
    {
        $validated = $request->validate([
            'nim' => [
                'required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('hotspot_vouchers', 'nim')->ignore($ignoreId),
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
            'nim.unique' => 'NIM ini sudah punya voucher.',
        ]);

        // Kebijakan kampus: password voucher sama dengan NIM bila tidak diisi.
        $validated['password'] = filled($validated['password'] ?? null)
            ? $validated['password']
            : $validated['nim'];

        return $validated;
    }

    /**
     * Terapkan voucher terpilih (atau semua yang pending/gagal) ke RADIUS.
     *
     * Tidak ada lagi batas per klik. Push ke MikroTik dulu satu perintah API per
     * NIM, jadi 892 voucher harus dipecah beberapa klik; menulis ke RADIUS cukup
     * beberapa INSERT bulk, jadi sekali klik selesai.
     */
    public function push(Request $request)
    {
        $request->validate([
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
        ]);

        $ids = $request->input('ids', []);

        // Dijaga sejak awal: RADIUS mati berarti tidak satu baris pun akan sampai,
        // dan menandai 892 voucher 'failed' karena satu koneksi yang putus hanya
        // membuang jejak status yang sebelumnya benar.
        $health = $this->radius->health();

        if (! $health['success']) {
            return back()->with('error', 'Server RADIUS tidak bisa dihubungi: '
                .Str::limit((string) ($health['error'] ?? 'unknown'), 150));
        }

        $base = HotspotVoucher::query()
            ->when(! empty($ids), fn ($q) => $q->whereIn('id', $ids))
            ->when(empty($ids), fn ($q) => $q->whereIn('status', [
                HotspotVoucher::STATUS_PENDING,
                HotspotVoucher::STATUS_FAILED,
            ]));

        if ((clone $base)->doesntExist()) {
            return back()->with('error', 'Tidak ada voucher yang perlu diterapkan ke RADIUS.');
        }

        // lazyById: barisnya dialirkan per 500, jadi jumlah voucher tidak lagi
        // menentukan besar memori yang dipakai satu request.
        $result = $this->applyToRadius($base->lazyById(500));

        $message = "Terapkan ke RADIUS: {$result['ok']} voucher berhasil";

        if ($result['failed'] > 0) {
            $message .= ", {$result['failed']} gagal (".Str::limit((string) $result['error'], 120).')';
        }

        return back()->with($result['failed'] > 0 ? 'error' : 'success', $message.'.');
    }

    /**
     * Terapkan satu voucher (tombol per baris).
     */
    public function pushOne(int $id)
    {
        $voucher = HotspotVoucher::findOrFail($id);

        $health = $this->radius->health();

        if (! $health['success']) {
            return back()->with('error', 'Server RADIUS tidak bisa dihubungi: '
                .Str::limit((string) ($health['error'] ?? 'unknown'), 150));
        }

        $result = $this->applyToRadius([$voucher]);

        return $result['ok'] === 1
            ? back()->with('success', "Voucher NIM {$voucher->nim} aktif di RADIUS.")
            : back()->with('error', "Menerapkan NIM {$voucher->nim} ke RADIUS gagal: "
                .Str::limit((string) $result['error'], 150));
    }

    /**
     * Tulis voucher ke RADIUS, lalu catat hasilnya sekali jalan.
     *
     * @param  iterable<int,HotspotVoucher>  $vouchers
     * @return array{ok:int,failed:int,error:?string}
     */
    protected function applyToRadius(iterable $vouchers): array
    {
        return $this->applier->apply($vouchers);
    }

    /**
     * Blokir / buka blokir voucher di RADIUS tanpa menghapus kredensialnya.
     *
     * Blokirnya cuma satu baris 'Auth-Type := Reject' di radcheck — password
     * mahasiswa tidak ditulis ulang, dan membukanya kembali hanya menghapus baris
     * itu. Karena penolakannya tercatat atas nama NIM yang jelas, radpostauth bisa
     * membedakan mahasiswa yang diblokir dari yang salah ketik NIM.
     */
    public function toggle(int $id)
    {
        $voucher = HotspotVoucher::findOrFail($id);

        $disable = $voucher->status !== HotspotVoucher::STATUS_DISABLED;

        try {
            $disable
                ? $this->radius->disable($voucher->nim)
                : $this->radius->enable($voucher->nim);
        } catch (\Throwable $e) {
            return back()->with('error', "Gagal mengubah status NIM {$voucher->nim} di RADIUS: "
                .Str::limit($e->getMessage(), 150));
        }

        $voucher->forceFill([
            // Buka blokir tidak otomatis berarti 'aktif': kalau kredensialnya belum
            // pernah sampai ke RADIUS, yang benar adalah 'pending'.
            'status' => $disable
                ? HotspotVoucher::STATUS_DISABLED
                : ($voucher->synced_at ? HotspotVoucher::STATUS_SYNCED : HotspotVoucher::STATUS_PENDING),
            'last_error' => null,

            // Blokir operator sengaja tanpa alasan tertulis. Sinkronisasi PMB hanya
            // menghidupkan kembali baris yang dimatikannya sendiri — yang
            // disabled_reason-nya terisi — jadi keputusan ini tidak ikut dibatalkan.
            'disabled_reason' => null,
        ])->save();

        return back()->with('success', "Voucher NIM {$voucher->nim} "
            .($disable ? 'diblokir di RADIUS' : 'diaktifkan kembali').'.');
    }

    /**
     * Putuskan sesi hotspot yang sedang aktif untuk satu NIM.
     *
     * Ini satu-satunya hal yang masih harus lewat API router: sesi yang sedang
     * berjalan hanya diketahui router, dan memutusnya lewat RADIUS butuh CoA
     * (port 3799) yang belum dipasang. Routernya diambil dari pilihan halaman,
     * bukan dari voucher->router_host yang bisa jadi alamat lama.
     */
    public function kick(Request $request, int $id)
    {
        $voucher = HotspotVoucher::findOrFail($id);
        $host = $this->resolveHost($request);

        try {
            $kicked = $this->mikrotik->kickHotspotActive($host, $voucher->nim);
        } catch (\Throwable $e) {
            return back()->with('error', "Gagal memutus sesi NIM {$voucher->nim}: ".Str::limit($e->getMessage(), 150));
        }

        return back()->with('success', $kicked > 0
            ? "{$kicked} sesi aktif NIM {$voucher->nim} diputus di {$host}."
            : "Tidak ada sesi aktif untuk NIM {$voucher->nim} di {$host}.");
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
            return back()->with('error', 'Router pencatat belum ditentukan. Pilih router MikroTik terlebih dahulu, '
                .'atau isi HOTSPOT_ROUTER_HOST di .env.');
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

        return back()->with('success', $message . '. Semua berstatus pending — klik "Terapkan ke RADIUS" untuk mengaktifkan.');
    }

    /**
     * Tarik daftar mahasiswa dari API SISKA/PMB menjadi voucher pending.
     *
     * Tidak ada file yang diunggah: daftarnya diambil langsung dari SISKA, dan
     * password mahasiswa dibentuk dari tanggal lahirnya. Mahasiswa yang tanggal
     * lahirnya belum terisi di SISKA tetap dapat voucher dengan password NIM,
     * dan jumlahnya disebut di pesan hasil supaya bisa ditindaklanjuti.
     *
     * Tarikan ini juga menutup akses NIM yang sudah tidak ada di SISKA. Yang
     * sengaja TIDAK tersedia di sini adalah --force: kalau pengaman penurunan
     * drastis menyala, keputusan menembusnya harus lewat terminal, bukan satu
     * klik di halaman yang bisa memutus WiFi seluruh kampus.
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
            return back()->with('error', 'Router pencatat belum ditentukan. Pilih router MikroTik terlebih dahulu, '
                .'atau isi HOTSPOT_ROUTER_HOST di .env.');
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

        if ($report['revived'] > 0) {
            $message .= ", {$report['revived']} hidup kembali";
        }

        if ($report['deactivated'] > 0) {
            $message .= ", {$report['deactivated']} ditutup karena sudah tidak ada di SISKA";
        }

        if ($report['by_nim'] > 0) {
            $message .= ". {$report['by_nim']} mahasiswa belum punya tanggal lahir di SISKA, jadi passwordnya "
                . 'memakai NIM (contoh: ' . implode(', ', array_slice($report['nim_samples'], 0, 3)) . ')';
        }

        if ($report['skipped'] > 0) {
            $message .= ", {$report['skipped']} NIM dilewati karena tidak bisa jadi username hotspot";
        }

        $message .= '. Voucher baru berstatus pending — klik "Terapkan ke RADIUS" untuk mengaktifkan.';

        // Penonaktifan yang gagal sampai ke RADIUS lebih penting daripada
        // hitungan di atas: mahasiswanya masih bisa login padahal tabel sudah
        // menyebutnya nonaktif.
        if ($report['deactivate_failed'] > 0) {
            return back()->with('warning', $message . " Namun {$report['deactivate_failed']} penonaktifan gagal "
                . 'ditulis ke RADIUS, jadi mahasiswanya masih bisa login — jalankan '
                . '`php artisan radius:reconcile` untuk memperbaikinya.');
        }

        // Pengaman penurunan drastis dan tarikan bersaring hanya melaporkan alasan;
        // menembusnya butuh --force yang memang cuma ada di terminal.
        if ($report['deactivate_candidates'] > 0 && $report['deactivate_skipped'] !== null) {
            return back()->with('warning', $message . ' Catatan: '
                . Str::limit($report['deactivate_skipped'], 400));
        }

        return back()->with('success', $message);
    }

    /**
     * Query voucher sesuai filter/pilihan yang sedang aktif di halaman.
     *
     * Tidak lagi disaring per router: export dan cetak harus memuat baris yang
     * sama dengan yang dilihat operator di tabel.
     */
    protected function filteredQuery(Request $request)
    {
        $ids = array_filter(explode(',', (string) $request->query('ids')));

        return HotspotVoucher::query()
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
        $rows = [['NIM', 'Nama', 'Password', 'Prodi', 'Fakultas', 'Paket', 'Router Pencatat', 'Status', 'Alasan Nonaktif', 'Asal', 'Batch', 'Berlaku Sampai', 'Terakhir Diterapkan']];

        foreach ($this->filteredQuery($request)->cursor() as $voucher) {
            $rows[] = [
                $voucher->nim,
                $voucher->student_name,
                $voucher->password,
                $voucher->program,
                $voucher->faculty,
                $voucher->profile,
                $voucher->router_host,
                strtoupper($voucher->status),
                $voucher->disabled_reason,
                $voucher->source,
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
        $vouchers = $this->filteredQuery($request)->limit(self::PRINT_LIMIT)->get();

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
     * JSON: sesi hotspot yang sedang aktif di router terpilih, digabung dengan
     * data mahasiswa.
     *
     * Nama dicari tanpa menyaring router_host: satu voucher berlaku di semua
     * router, jadi menyaringnya justru membuat mahasiswa yang login di router lain
     * tampil sebagai "tidak terdaftar".
     */
    public function activeUsers(Request $request)
    {
        $host = $this->resolveHost($request);
        $active = $this->mikrotik->getHotspotActive($host);

        $names = HotspotVoucher::query()
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
