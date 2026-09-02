<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\MikrotikService;
use App\Services\RadiusService;
use App\Support\MikrotikRateLimit;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Paket Hotspot: isi dari group RADIUS yang dipakai voucher mahasiswa.
 *
 * Halaman voucher menentukan SIAPA yang memakai paket mana — kolom profile-nya
 * jatuh ke radusergroup.groupname. Halaman ini menentukan APA ISI paket itu:
 * kecepatan, batas sesi, batas idle. Keduanya sengaja dipisah karena yang pertama
 * berubah setiap semester untuk ribuan baris, sementara yang kedua berubah
 * beberapa kali setahun untuk lima baris — dan yang kedua berlaku ke semua orang
 * sekaligus.
 *
 * Yang perlu dipahami sebelum mengubah kelas ini: group tanpa policy TIDAK
 * menolak siapa pun. FreeRADIUS menjawab Access-Accept tanpa atribut, mahasiswa
 * tetap login, dan batas kecepatan yang disangka berlaku tidak pernah ada. Itulah
 * keadaan yang ditemukan di server sebelum halaman ini dibuat, dan itulah yang
 * membuat 'has_policy' ditonjolkan, bukan disembunyikan.
 *
 * Penyimpanannya radgroupreply itu sendiri — CIMS tidak punya tabel cermin.
 * Paket tidak punya daur hidup di sisi CIMS (tidak ada yang perlu diarsipkan atau
 * ditelusuri riwayatnya), jadi tabel kedua hanya akan menambah satu sumber
 * kebenaran lagi beserta kewajiban merekonsiliasinya.
 *
 * Kelas ini tidak pernah menyentuh radgroupcheck: grant CIMS di tabel itu hanya
 * SELECT, dan syarat login (Auth-Type, Simultaneous-Use) bukan hal yang pantas
 * berubah karena seseorang menyimpan formulir kecepatan.
 */
class HotspotPackageWebController extends Controller
{
    /**
     * Batas atas kolom groupname di skema FreeRADIUS stok (varchar 64). Divalidasi
     * di sini supaya nama yang kepanjangan ditolak dengan pesan, bukan dipotong
     * diam-diam oleh MySQL lalu menjadi group lain yang tidak dipakai siapa pun.
     */
    protected const NAME_MAX = 64;

    /**
     * Nama group yang sah: huruf, angka, titik, garis bawah, dan tanda hubung.
     *
     * Spasi ditolak dengan sengaja. RouterOS menerima nama ber-spasi, tapi nama
     * group RADIUS ikut muncul di config router dan di file konfigurasi
     * FreeRADIUS, dan di sanalah spasi berubah menjadi masalah yang sulit dilihat.
     */
    protected const NAME_PATTERN = '/^[A-Za-z0-9._-]+$/';

    public function __construct(
        protected RadiusService $radius,
        protected MikrotikService $mikrotik,
    ) {
    }

    /**
     * Daftar paket beserta jumlah pemakainya.
     *
     * Daftar user-profile router dikirim sebagai deferred prop terpisah: ia hanya
     * dipakai untuk membandingkan nilai Mikrotik-Group, dan router yang sedang mati
     * tidak boleh membuat halaman paket ikut gagal dimuat.
     */
    public function index(Request $request)
    {
        $host = $this->resolveHost($request);

        return Inertia::render('Hotspot/Packages', [
            'packages' => $this->radius->packages(),
            'defaultGroup' => $this->radius->defaultGroup() ?: null,
            'managedAttributes' => RadiusService::MANAGED_GROUP_REPLY,
            'radiusConfigured' => $this->radius->configured(),
            'routerHost' => $host,
            'routers' => $this->mikrotikRouters(),
            'canManage' => (bool) $request->user()?->can('manage devices'),

            'connection' => Inertia::defer(fn () => $this->radius->health()),

            // Grup 'router' dipisah supaya menunggu router tidak menahan status
            // RADIUS — yang satu menentukan halaman ini bisa menyimpan atau tidak,
            // yang lain cuma pelengkap.
            'routerProfiles' => Inertia::defer(fn () => $this->mikrotik->getHotspotProfiles($host), 'router'),
        ]);
    }

    /**
     * Buat paket baru.
     *
     * Menyimpan berarti menulis policy, dan group yang belum ada akan "muncul"
     * begitu barisnya ada — di RADIUS tidak ada tabel daftar group tersendiri.
     * Karena itu nama yang sudah dipakai ditolak di sini: tanpa penjagaan ini,
     * "membuat paket baru" dengan nama yang sama diam-diam menimpa paket lain.
     */
    public function store(Request $request)
    {
        $data = $this->validatePackage($request);

        $existing = collect($this->radius->packages())
            ->first(fn (array $package) => strcasecmp((string) $package['name'], $data['name']) === 0);

        if ($existing && ($existing['has_policy'] ?? false)) {
            throw ValidationException::withMessages([
                'name' => "Paket '{$data['name']}' sudah ada. Buka paket itu untuk mengubah isinya.",
            ]);
        }

        $this->radius->savePackage($data['name'], $this->attributesFrom($data));

        return back()->with('success', $this->savedMessage($data['name'], $existing['members'] ?? 0));
    }

    /**
     * Ubah isi paket yang sudah ada.
     *
     * Nama tidak bisa diubah di sini. Mengganti groupname berarti memindahkan
     * seluruh anggotanya juga — itu operasi di radusergroup, milik halaman voucher,
     * dan menamainya "rename" di halaman ini hanya akan membuat paket kosong
     * berisi nama baru sementara semua mahasiswa masih menunjuk yang lama.
     */
    public function update(Request $request, string $group)
    {
        $data = $this->validatePackage($request, $group);

        $this->radius->savePackage($group, $this->attributesFrom($data));

        return back()->with('success', $this->savedMessage($group, $this->radius->packageMembers($group)));
    }

    /**
     * Hapus policy paket.
     *
     * Ditolak selama masih ada voucher yang memakainya. Menghapus policy tidak
     * memutus siapa pun — justru sebaliknya: anggotanya tetap bisa login, hanya
     * tanpa batas kecepatan sama sekali. Kegagalan yang tidak terasa seperti
     * kegagalan itulah alasan penjagaan ini ada.
     */
    public function destroy(string $group)
    {
        $members = $this->radius->packageMembers($group);

        if ($members > 0) {
            return back()->with('error', "Paket '{$group}' masih dipakai {$members} voucher. "
                .'Pindahkan dulu voucher itu ke paket lain di halaman Voucher WiFi Mahasiswa — '
                .'menghapus policy-nya sekarang membuat mereka login tanpa batas kecepatan.');
        }

        $deleted = $this->radius->deletePackage($group);

        if ($deleted['reply'] === 0 && $deleted['check'] === 0) {
            return back()->with('error', "Paket '{$group}' tidak punya baris policy untuk dihapus.");
        }

        $message = "Paket '{$group}' dihapus ({$deleted['reply']} atribut).";

        if ($deleted['check'] > 0) {
            $message .= " {$deleted['check']} baris di radgroupcheck tidak ikut terhapus — "
                .'CIMS hanya boleh membacanya. Hapus dari server RADIUS bila memang tidak dipakai lagi.';
        }

        return back()->with('success', $message);
    }

    /**
     * @return array<string,mixed>
     */
    protected function validatePackage(Request $request, ?string $group = null): array
    {
        $rules = [
            // Nol berarti "tanpa batas" dan menghapus barisnya; lihat
            // RadiusService::savePackage(). Batas atasnya 10 Gbps, sekadar
            // penjagaan terhadap salah ketik nol yang kebanyakan.
            'download' => ['required', 'numeric', 'min:0.064', 'max:10000'],
            'upload' => ['required', 'numeric', 'min:0.064', 'max:10000'],

            // Dalam menit di formulir, detik di RADIUS. Operator berpikir dalam
            // menit dan jam; RADIUS tidak menerima satuan.
            'session_timeout' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'idle_timeout' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'interim_interval' => ['nullable', 'integer', 'min:1', 'max:1440'],

            'mikrotik_group' => ['nullable', 'string', 'max:64'],

            // Mode lanjut: nilai Mikrotik-Rate-Limit ditulis apa adanya (burst,
            // threshold, priority). Kalau diisi, dua angka Mbps di atas diabaikan.
            'rate_limit_raw' => ['nullable', 'string', 'max:191'],
        ];

        if ($group === null) {
            $rules['name'] = ['required', 'string', 'max:'.self::NAME_MAX, 'regex:'.self::NAME_PATTERN];
        }

        $data = $request->validate($rules, [], [
            'name' => 'nama paket',
            'download' => 'kecepatan unduh',
            'upload' => 'kecepatan unggah',
            'session_timeout' => 'batas sesi',
            'idle_timeout' => 'batas diam',
            'interim_interval' => 'interval laporan',
            'mikrotik_group' => 'user-profile router',
            'rate_limit_raw' => 'rate limit lanjutan',
        ]);

        $data['name'] = trim((string) ($group ?? $data['name']));

        // Divalidasi terpisah dari regex nama supaya pesannya menyebut bentuk yang
        // benar, bukan "format tidak valid" atas nilai yang operator tidak tahu
        // aturannya. rate_limit_raw memang boleh ber-spasi — itu justru cirinya.
        if (filled($data['rate_limit_raw'] ?? null) && ! $this->looksLikeRateLimit($data['rate_limit_raw'])) {
            throw ValidationException::withMessages([
                'rate_limit_raw' => 'Bentuknya rx/tx, boleh diikuti burst dan priority. '
                    .'Contoh: 2M/8M 4M/16M 3M/12M 8 8',
            ]);
        }

        return $data;
    }

    /**
     * Formulir → atribut radgroupreply.
     *
     * Nilai kosong sengaja dikirim sebagai string kosong, bukan dihilangkan dari
     * array: savePackage() memaknainya sebagai "hapus baris itu", dan itulah arti
     * kolom yang dikosongkan operator.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,string>
     */
    protected function attributesFrom(array $data): array
    {
        $rate = filled($data['rate_limit_raw'] ?? null)
            ? trim((string) $data['rate_limit_raw'])
            : MikrotikRateLimit::format((float) $data['upload'], (float) $data['download']);

        return [
            'Mikrotik-Rate-Limit' => $rate,
            'Mikrotik-Group' => trim((string) ($data['mikrotik_group'] ?? '')),
            'Session-Timeout' => $this->minutesToSeconds($data['session_timeout'] ?? null),
            'Idle-Timeout' => $this->minutesToSeconds($data['idle_timeout'] ?? null),
            'Acct-Interim-Interval' => $this->minutesToSeconds($data['interim_interval'] ?? null),
        ];
    }

    protected function minutesToSeconds(mixed $minutes): string
    {
        return filled($minutes) && (int) $minutes > 0 ? (string) ((int) $minutes * 60) : '';
    }

    /**
     * Bentuk Mikrotik-Rate-Limit yang masih bisa dipertanggungjawabkan.
     *
     * Tidak diurai penuh: tata bahasanya milik RouterOS, dan menirunya di sini
     * berarti menolak nilai sah yang belum kami kenal. Yang dijaga cuma bahwa
     * isinya token rx/tx dan angka — bukan kalimat yang salah tempel.
     */
    protected function looksLikeRateLimit(string $value): bool
    {
        foreach (preg_split('/\s+/', trim($value)) ?: [] as $token) {
            if (! preg_match('/^\d+(?:\.\d+)?[kKmMgG]?(?:\/\d+(?:\.\d+)?[kKmMgG]?)?$/', $token)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Pesan sesudah menyimpan.
     *
     * Menyebut jumlah anggota dan menyebut bahwa perubahannya berlaku pada login
     * berikutnya. Keduanya pertanyaan pertama operator, dan yang kedua mudah salah
     * disangka: rlm_sql membaca policy setiap Access-Request, jadi tidak ada
     * FreeRADIUS yang perlu direstart dan tidak ada voucher yang perlu dipush
     * ulang — tapi sesi yang sedang berjalan tetap memakai batas lamanya.
     */
    protected function savedMessage(string $group, int $members): string
    {
        $message = "Paket '{$group}' disimpan.";

        if ($members > 0) {
            $message .= " Berlaku untuk {$members} voucher pada login berikutnya";
            $message .= ' — sesi yang sedang berjalan masih memakai batas lama.';
        } else {
            $message .= ' Belum ada voucher yang memakainya; pilih paket ini di kolom Paket'
                .' pada halaman Voucher WiFi Mahasiswa.';
        }

        return $message;
    }

    /**
     * Router yang ditanyai daftar user-profile-nya. Sama urutannya dengan halaman
     * voucher supaya keduanya menunjuk router yang sama.
     */
    protected function resolveHost(Request $request): ?string
    {
        return $request->query('host')
            ?: (config('services.hotspot.router_host')
                ?: (config('services.mikrotik.host') ?: ($this->mikrotikRouters()[0]['ip'] ?? null)));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function mikrotikRouters(): array
    {
        return Device::query()
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
            ])
            ->unique('ip')
            ->values()
            ->all();
    }
}
