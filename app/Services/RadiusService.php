<?php

namespace App\Services;

use App\Models\HotspotVoucher;
use App\Support\MikrotikRateLimit;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;

/**
 * Satu-satunya pintu CIMS ke database FreeRADIUS.
 *
 * Voucher hotspot tidak lagi dituliskan ke /ip/hotspot/user di setiap router.
 * Sekarang ia menjadi baris di tabel RADIUS, dan router hotspot cukup bertanya
 * lewat Access-Request. Konsekuensinya satu identitas mahasiswa berlaku di semua
 * router sekaligus, dan sesi/pemakaiannya tercatat di radacct.
 *
 * Dua aturan yang menjaga service ini tetap aman dipakai di database yang bukan
 * milik CIMS:
 *
 *   1. Hanya atribut pada MANAGED_CHECK/MANAGED_REPLY yang boleh dihapus. Baris
 *      milik konfigurasi lain di radcheck/radreply tidak pernah tersentuh, jadi
 *      CIMS tidak bisa merusak layanan RADIUS yang sudah berjalan.
 *   2. Semua penulisan lewat transaksi per rombongan: satu rombongan berhasil
 *      seluruhnya atau tidak sama sekali, tidak pernah setengah jalan yang
 *      membuat mahasiswa punya password tanpa group.
 *
 * Status baris voucher (pending/synced/failed) sengaja BUKAN urusan service ini —
 * itu tabel CIMS. Service melaporkan id mana yang berhasil dan mana yang gagal,
 * lalu pemanggilnya menulis statusnya sekali jalan.
 */
class RadiusService
{
    /**
     * Atribut radcheck yang dikelola CIMS. Cleartext-Password harus benar-benar
     * teks terang: portal hotspot MikroTik memakai CHAP, dan CHAP mengharuskan
     * server memegang password aslinya untuk mencocokkan challenge.
     */
    public const MANAGED_CHECK = ['Cleartext-Password', 'Auth-Type'];

    /** Atribut radreply yang dikelola CIMS. */
    public const MANAGED_REPLY = ['Mikrotik-Group', 'Mikrotik-Rate-Limit', 'Session-Timeout'];

    /**
     * Atribut radgroupreply yang dikelola halaman Paket Hotspot.
     *
     * Daftar ini menjaga hal yang sama seperti MANAGED_REPLY, tapi untuk sesuatu
     * yang lebih mudah menimbulkan penyesalan: isi group dipakai bersama seluruh
     * mahasiswa. Kalau seseorang pernah menambahkan atribut lain di group ini
     * dengan tangan — `Framed-Pool`, `Mikrotik-Address-List`, `Filter-Id` —
     * menyimpan formulir tidak boleh menghapusnya. Yang di luar daftar ini
     * ditampilkan sebagai keterangan dan tidak pernah disentuh.
     */
    public const MANAGED_GROUP_REPLY = [
        'Mikrotik-Rate-Limit',
        'Mikrotik-Group',
        'Session-Timeout',
        'Idle-Timeout',
        'Acct-Interim-Interval',
    ];

    /**
     * Atribut radgroupcheck yang dikelola halaman Paket Hotspot.
     *
     * Satu-satunya, dan sengaja satu-satunya. radgroupcheck bukan sekadar tempat
     * atribut lain: isinya SYARAT LOGIN, dan salah menuliskannya menolak seluruh
     * anggota paket sekaligus. Auth-Type, Expiration, Login-Time dan sisanya tetap
     * di luar jangkauan formulir — ditampilkan, tidak pernah ditulis.
     *
     * Simultaneous-Use masuk karena ia satu-satunya cara membatasi satu akun ke
     * satu sesi di SELURUH router. shared-users pada user-profile RouterOS hanya
     * berlaku di router yang memasangnya, jadi dua router berarti dua sesi — dan
     * itu justru bentuk berbagi akun yang paling sulit terlihat.
     */
    public const MANAGED_GROUP_CHECK = ['Simultaneous-Use'];

    /**
     * Ambang "sesi basi" dalam menit.
     *
     * Sesi yang laporan terakhirnya lebih tua dari ini hampir pasti sudah mati
     * tanpa Accounting-Stop. 15 menit dipilih supaya masih longgar untuk
     * Acct-Interim-Interval yang lazim (5–10 menit) tanpa perlu menunggu setengah
     * hari sebelum sebuah baris patut dicurigai.
     */
    public const STALE_AFTER_MINUTES = 15;

    /** Username per transaksi. Cukup besar untuk sekali klik, cukup kecil untuk packet MySQL. */
    protected const CHUNK = 500;

    public function connection(): ConnectionInterface
    {
        return DB::connection($this->connectionName());
    }

    public function connectionName(): string
    {
        return (string) (config('services.hotspot.radius.connection') ?: 'radius');
    }

    /** groupname yang dipakai bila kolom profile voucher kosong. */
    public function defaultGroup(): string
    {
        return trim((string) config('services.hotspot.radius.default_group'));
    }

    /** .env sudah cukup terisi untuk mencoba menyambung. */
    public function configured(): bool
    {
        $config = config('database.connections.'.$this->connectionName());

        return is_array($config)
            && filled($config['host'] ?? null)
            && filled($config['database'] ?? null)
            && filled($config['username'] ?? null);
    }

    /**
     * Keadaan server RADIUS untuk banner halaman voucher dan pengaman sebelum
     * push. Tidak pernah melempar exception: nilainya dipakai sebagai prop
     * halaman, dan RADIUS mati tidak boleh membuat halaman voucher ikut mati.
     *
     * @return array{success:bool,error:?string,database:?string,server:?string,users:int}
     */
    public function health(): array
    {
        if (! $this->configured()) {
            return [
                'success' => false,
                'error' => 'Koneksi RADIUS belum diatur — isi RADIUS_DB_HOST, RADIUS_DB_DATABASE, '
                    .'RADIUS_DB_USERNAME, dan RADIUS_DB_PASSWORD di .env.',
                'database' => null,
                'server' => null,
                'users' => 0,
            ];
        }

        try {
            $db = $this->connection();

            $users = (int) $db->table('radcheck')
                ->where('attribute', 'Cleartext-Password')
                ->distinct()
                ->count('username');

            return [
                'success' => true,
                'error' => null,
                'database' => (string) ($db->getConfig('database') ?? ''),
                'server' => $this->serverVersion($db),
                'users' => $users,
            ];
        } catch (\Throwable $e) {
            Log::warning('RADIUS connection failed: '.$e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'database' => (string) config('database.connections.'.$this->connectionName().'.database'),
                'server' => null,
                'users' => 0,
            ];
        }
    }

    protected function serverVersion(ConnectionInterface $db): ?string
    {
        try {
            return (string) ($db->selectOne('select version() as version')->version ?? null) ?: null;
        } catch (\Throwable) {
            // sqlite saat test tidak punya version().
            return null;
        }
    }

    /**
     * Terapkan sekumpulan voucher ke RADIUS.
     *
     * Kegagalan dilaporkan per rombongan, bukan per baris: satu transaksi yang
     * gagal membatalkan seluruh rombongannya, jadi seluruh id di dalamnya memang
     * belum sampai ke RADIUS. Pemanggil memakai ok_ids/failed_ids untuk menulis
     * status voucher sekali jalan, bukan satu-satu.
     *
     * Rombongannya dibentuk lewat LazyCollection supaya pemanggil boleh
     * menyerahkan cursor/lazyById: 892 voucher hari ini masih muat di memori,
     * puluhan ribu nanti belum tentu.
     *
     * @param  iterable<int,HotspotVoucher>  $vouchers
     * @return array{ok:int,failed:int,error:?string,ok_ids:array<int,int>,failed_ids:array<int,int>}
     */
    public function upsertMany(iterable $vouchers): array
    {
        $result = ['ok' => 0, 'failed' => 0, 'error' => null, 'ok_ids' => [], 'failed_ids' => []];
        $defaultGroup = $this->defaultGroup();

        foreach (LazyCollection::make($vouchers)->chunk(self::CHUNK) as $chunk) {
            $chunk = Collection::make($chunk->all());

            $ids = $chunk->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();

            try {
                $this->writeChunk($chunk, $defaultGroup);

                $result['ok'] += $chunk->count();
                $result['ok_ids'] = array_merge($result['ok_ids'], $ids);
            } catch (\Throwable $e) {
                Log::warning('RADIUS write failed: '.$e->getMessage());

                $result['failed'] += $chunk->count();
                $result['failed_ids'] = array_merge($result['failed_ids'], $ids);
                $result['error'] ??= $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * @return array{ok:int,failed:int,error:?string,ok_ids:array<int,int>,failed_ids:array<int,int>}
     */
    public function upsert(HotspotVoucher $voucher): array
    {
        return $this->upsertMany([$voucher]);
    }

    /**
     * Hapus atribut milik CIMS lalu tulis ulang. Bukan UPDATE baris demi baris:
     * radcheck/radreply/radusergroup tidak punya kunci unik pada (username,
     * attribute), jadi upsert per baris justru menumpuk duplikat.
     *
     * @param  Collection<int,HotspotVoucher>  $vouchers
     */
    protected function writeChunk(Collection $vouchers, string $defaultGroup): void
    {
        $usernames = $vouchers->pluck('nim')->map(fn ($nim) => (string) $nim)->unique()->values()->all();

        $check = [];
        $reply = [];
        $group = [];

        foreach ($vouchers as $voucher) {
            $rows = $voucher->toRadiusRows($defaultGroup);

            $check = array_merge($check, $rows['check']);
            $reply = array_merge($reply, $rows['reply']);
            $group = array_merge($group, $rows['group']);
        }

        $this->connection()->transaction(function () use ($usernames, $check, $reply, $group) {
            $db = $this->connection();

            $db->table('radcheck')->whereIn('username', $usernames)
                ->whereIn('attribute', self::MANAGED_CHECK)->delete();
            $db->table('radreply')->whereIn('username', $usernames)
                ->whereIn('attribute', self::MANAGED_REPLY)->delete();

            // radusergroup dihapus seluruhnya per username, bukan per groupname:
            // tabelnya tidak punya kunci unik, dan keanggotaan group memang
            // sepenuhnya ditentukan kolom profile voucher. radius:doctor
            // memperingatkan kalau daloRADIUS ikut menulis di database ini.
            $db->table('radusergroup')->whereIn('username', $usernames)->delete();

            foreach ([['radcheck', $check], ['radreply', $reply], ['radusergroup', $group]] as [$table, $rows]) {
                if ($rows !== []) {
                    $db->table($table)->insert($rows);
                }
            }
        });
    }

    /**
     * Tolak login untuk NIM-NIM ini tanpa menghapus kredensialnya.
     *
     * Barisnya sengaja ditinggalkan di radcheck: 'Auth-Type := Reject' membuat
     * radpostauth mencatat penolakan atas nama NIM yang jelas, bukan "unknown
     * user" yang tidak bisa dibedakan dari salah ketik. Mengaktifkan kembali pun
     * cuma menghapus satu baris, bukan menulis ulang password.
     *
     * @param  array<int,string>  $nims
     */
    public function disableMany(array $nims): int
    {
        $affected = 0;

        foreach (array_chunk(array_values(array_unique($nims)), self::CHUNK) as $chunk) {
            $this->connection()->transaction(function () use ($chunk, &$affected) {
                $db = $this->connection();

                $db->table('radcheck')->whereIn('username', $chunk)
                    ->where('attribute', 'Auth-Type')->delete();

                $db->table('radcheck')->insert(array_map(fn ($nim) => [
                    'username' => $nim,
                    'attribute' => 'Auth-Type',
                    'op' => ':=',
                    'value' => 'Reject',
                ], $chunk));

                $affected += count($chunk);
            });
        }

        return $affected;
    }

    /** @param array<int,string> $nims */
    public function enableMany(array $nims): int
    {
        $affected = 0;

        foreach (array_chunk(array_values(array_unique($nims)), self::CHUNK) as $chunk) {
            $affected += (int) $this->connection()->table('radcheck')
                ->whereIn('username', $chunk)
                ->where('attribute', 'Auth-Type')
                ->delete();
        }

        return $affected;
    }

    public function disable(string $nim): int
    {
        return $this->disableMany([$nim]);
    }

    public function enable(string $nim): int
    {
        return $this->enableMany([$nim]);
    }

    /**
     * Cabut seluruh jejak NIM-NIM ini dari RADIUS. Dipakai saat vouchernya dihapus
     * dari CIMS — kalau tidak, mahasiswa yang barisnya sudah hilang dari halaman
     * voucher masih bisa login.
     *
     * radacct dan radpostauth tidak disentuh: itu catatan sejarah pemakaian, dan
     * riwayat tidak dihapus hanya karena akunnya ditutup.
     *
     * @param  array<int,string>  $nims
     */
    public function forgetMany(array $nims): int
    {
        $affected = 0;

        foreach (array_chunk(array_values(array_unique($nims)), self::CHUNK) as $chunk) {
            $this->connection()->transaction(function () use ($chunk, &$affected) {
                $db = $this->connection();

                $db->table('radcheck')->whereIn('username', $chunk)
                    ->whereIn('attribute', self::MANAGED_CHECK)->delete();
                $db->table('radreply')->whereIn('username', $chunk)
                    ->whereIn('attribute', self::MANAGED_REPLY)->delete();
                $db->table('radusergroup')->whereIn('username', $chunk)->delete();

                $affected += count($chunk);
            });
        }

        return $affected;
    }

    public function forget(string $nim): int
    {
        return $this->forgetMany([$nim]);
    }

    /**
     * NIM yang sudah punya password di RADIUS. Dipakai rekonsiliasi untuk mencari
     * selisih dua arah antara CIMS dan RADIUS.
     *
     * @param  array<int,string>  $nims
     * @return array<int,string>
     */
    public function existingUsernames(array $nims): array
    {
        $found = [];

        foreach (array_chunk(array_values(array_unique($nims)), self::CHUNK) as $chunk) {
            $found = array_merge($found, $this->connection()->table('radcheck')
                ->whereIn('username', $chunk)
                ->where('attribute', 'Cleartext-Password')
                ->distinct()
                ->pluck('username')
                ->map(fn ($username) => (string) $username)
                ->all());
        }

        return $found;
    }

    /**
     * Sesi yang masih terbuka di radacct — seluruh router sekaligus.
     *
     * Syaratnya sengaja sama persis dengan yang dipakai FreeRADIUS sendiri untuk
     * menghitung Simultaneous-Use: `acctstoptime IS NULL`. Jadi angka di panel ini
     * bukan perkiraan yang mirip, melainkan bilangan yang sama yang akan dipakai
     * server kalau nanti diminta menolak login kedua sebuah NIM.
     *
     * Dua hal yang membedakannya dari /ip/hotspot/active di router:
     *
     *   1. Cakupannya semua NAS. Router hanya tahu sesi miliknya sendiri — dan
     *      justru satu akun yang dipakai bersamaan di dua router berbeda itulah
     *      yang tidak akan pernah terlihat dari sana.
     *   2. Ia bisa keliru. Baris yang tidak pernah ditutup — HP mati, router
     *      reboot, Accounting-Stop hilang di jalan — tetap terhitung "online".
     *      Karena itu setiap baris membawa umur laporan terakhirnya: baris-baris
     *      itulah yang akan mengunci mahasiswa dari login berikutnya begitu
     *      Simultaneous-Use dinyalakan, dan operator harus melihatnya lebih dulu.
     *
     * Tidak pernah melempar exception: dipakai endpoint panel, dan RADIUS mati
     * tidak boleh membuat halaman voucher ikut gagal.
     *
     * @return array{configured:bool,error:?string,total:int,shown:int,stale:int,stale_after_minutes:int,truncated:bool,shared:array<int,array<string,mixed>>,sessions:array<int,array<string,mixed>>}
     */
    public function activeSessions(int $limit = 200): array
    {
        $blank = [
            'configured' => $this->configured(),
            'error' => null,
            'total' => 0,
            'shown' => 0,
            'stale' => 0,
            'stale_after_minutes' => self::STALE_AFTER_MINUTES,
            'truncated' => false,
            'shared' => [],
            'sessions' => [],
        ];

        if (! $this->configured()) {
            return ['error' => 'Koneksi RADIUS belum diatur — panel ini membaca radacct '
                .'di server RADIUS, bukan router.'] + $blank;
        }

        try {
            return $this->readSessions(max($limit, 1)) + $blank;
        } catch (\Throwable $e) {
            Log::warning('RADIUS session listing failed: '.$e->getMessage());

            return ['error' => $e->getMessage()] + $blank;
        }
    }

    /**
     * Bagian activeSessions() yang boleh gagal. Dipisah supaya penanganan error
     * tinggal satu tempat dan alur bacanya tidak tenggelam di dalam try.
     *
     * Urutannya "yang paling lama tidak melapor lebih dulu", bukan yang terbaru
     * login. Itu keputusan yang menentukan: kalau daftarnya terpotong oleh $limit,
     * yang boleh hilang adalah baris yang sehat — baris basi justru satu-satunya
     * yang wajib terlihat.
     *
     * @return array<string,mixed>
     */
    protected function readSessions(int $limit): array
    {
        $db = $this->connection();
        $now = $this->serverNow($db);
        $cutoff = $now->copy()->subMinutes(self::STALE_AFTER_MINUTES)->format('Y-m-d H:i:s');

        $total = (int) $db->table('radacct')->whereNull('acctstoptime')->count();

        // coalesce() supaya sesi yang belum pernah dapat interim update tetap
        // terbandingkan lewat waktu mulainya. Keduanya ada di skema stok
        // FreeRADIUS 3.x, jadi tidak perlu dijaga keberadaannya di sini.
        $stale = (int) $db->table('radacct')
            ->whereNull('acctstoptime')
            ->whereRaw('coalesce(acctupdatetime, acctstarttime) < ?', [$cutoff])
            ->count();

        $rows = $db->table('radacct')
            ->whereNull('acctstoptime')
            ->orderByRaw('coalesce(acctupdatetime, acctstarttime) asc')
            ->limit($limit)
            ->get([
                'acctsessionid', 'username', 'nasipaddress', 'acctstarttime',
                'acctupdatetime', 'acctsessiontime', 'acctinputoctets',
                'acctoutputoctets', 'callingstationid', 'framedipaddress',
            ]);

        $sessions = Collection::make($rows)
            ->map(fn ($row) => $this->describeSession($row, $now))
            ->values()
            ->all();

        return [
            'total' => $total,
            'shown' => count($sessions),
            'stale' => $stale,
            'truncated' => $total > count($sessions),
            'shared' => $this->sharedUsernames($db),
            'sessions' => $sessions,
        ];
    }

    /**
     * NIM yang sedang punya lebih dari satu sesi terbuka sekaligus.
     *
     * Inilah jawaban langsung atas "apakah ada akun yang dipakai bersama" — dan ia
     * tersedia tanpa perlu menyalakan Simultaneous-Use lebih dulu. Melihat dulu,
     * menolak kemudian: daftar ini yang memberi tahu berapa banyak mahasiswa akan
     * terkena begitu batasnya dipasang.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function sharedUsernames(ConnectionInterface $db): array
    {
        $rows = $db->table('radacct')
            ->whereNull('acctstoptime')
            ->select('username')
            ->selectRaw('count(*) as total')
            ->groupBy('username')
            ->havingRaw('count(*) > 1')
            ->orderByRaw('count(*) desc')
            ->limit(50)
            ->get();

        return Collection::make($rows)
            ->map(fn ($row) => [
                'username' => (string) $row->username,
                'sessions' => (int) $row->total,
            ])
            ->values()
            ->all();
    }

    /**
     * Jam yang dipakai menilai umur sesi: jam server RADIUS, bukan jam CIMS.
     *
     * radacct diisi FreeRADIUS memakai waktu lokal servernya. APP_TIMEZONE di sini
     * Asia/Makassar sementara server Ubuntu lazim berjalan di UTC — memakai now()
     * milik PHP akan membuat setiap sesi terlihat basi delapan jam, dan panel yang
     * menuduh semua orang lebih buruk daripada tidak ada panel.
     *
     * Nilainya diurai dengan timezone yang sama dengan timestamp radacct (keduanya
     * lewat Carbon::parse tanpa zona), jadi selisihnya benar walau zonanya sendiri
     * tidak diketahui. Yang dibandingkan memang selisih, bukan jam dinding.
     */
    protected function serverNow(ConnectionInterface $db): Carbon
    {
        try {
            $value = trim((string) ($db->selectOne('select now() as server_now')->server_now ?? ''));

            if ($value !== '') {
                return Carbon::parse($value);
            }
        } catch (\Throwable) {
            // sqlite saat test tidak punya now().
        }

        return Carbon::now();
    }

    /**
     * Satu baris radacct sebagaimana perlu dibaca operator.
     *
     * `silent_for` dihitung dari acctupdatetime dengan acctstarttime sebagai
     * cadangan. Tanpa Acct-Interim-Interval, FreeRADIUS tidak pernah memperbarui
     * baris itu, jadi setiap sesi panjang akan tampak basi. Itu bukan salah hitung
     * — itu memang keadaan yang harus dibereskan sebelum Simultaneous-Use bisa
     * dipercaya — tapi `reported` dikirim supaya panel bisa menyebutkan bedanya,
     * bukan membiarkan operator menuduh mahasiswa yang tidak salah apa pun.
     *
     * Arah oktet mengikuti sudut pandang NAS, bukan mahasiswa: acctinputoctets
     * adalah yang MASUK ke router (unggahan mahasiswa), acctoutputoctets yang
     * KELUAR darinya (unduhan). Tertukar di sini berarti grafik pemakaian terbalik
     * dan tidak ada yang menyadarinya.
     *
     * @return array<string,mixed>
     */
    protected function describeSession(object $row, Carbon $now): array
    {
        $start = $this->moment($row->acctstarttime ?? null);
        $update = $this->moment($row->acctupdatetime ?? null);
        $reference = $update ?? $start;

        $silentFor = $reference ? max($now->getTimestamp() - $reference->getTimestamp(), 0) : null;

        return [
            'session_id' => trim((string) ($row->acctsessionid ?? '')) ?: null,
            'username' => (string) ($row->username ?? ''),
            'nas_ip' => trim((string) ($row->nasipaddress ?? '')) ?: null,
            'ip' => trim((string) ($row->framedipaddress ?? '')) ?: null,
            'mac' => trim((string) ($row->callingstationid ?? '')) ?: null,
            'started_at' => $start?->format('Y-m-d H:i:s'),
            'uptime_seconds' => $start ? max($now->getTimestamp() - $start->getTimestamp(), 0) : null,
            'silent_for' => $silentFor,
            'reported' => $update !== null,
            'stale' => $silentFor !== null && $silentFor > self::STALE_AFTER_MINUTES * 60,
            'bytes_in' => (int) ($row->acctinputoctets ?? 0),
            'bytes_out' => (int) ($row->acctoutputoctets ?? 0),
        ];
    }

    /**
     * Timestamp radacct menjadi Carbon. Null untuk kolom kosong dan untuk
     * '0000-00-00 00:00:00' — nilai sah di MySQL lama yang bukan waktu apa pun.
     */
    protected function moment(mixed $value): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Daftar group yang bisa dipilih sebagai paket voucher.
     *
     * Yang dianggap "ada" adalah group yang punya policy (radgroupreply /
     * radgroupcheck) — di situlah rate limit dan batas sesi tinggal. Group yang
     * hanya muncul di radusergroup ikut disertakan supaya profile yang sudah
     * dipakai voucher tidak hilang dari dropdown, tapi group tanpa policy tidak
     * memberi batas apa pun; radius:doctor yang menandai hal itu.
     *
     * Tidak pernah melempar exception: dipakai sebagai prop halaman.
     *
     * @return array<int,string>
     */
    public function groups(): array
    {
        if (! $this->configured()) {
            return [];
        }

        try {
            $db = $this->connection();

            return Collection::make($db->table('radgroupreply')->distinct()->pluck('groupname'))
                ->merge($db->table('radgroupcheck')->distinct()->pluck('groupname'))
                ->merge($db->table('radusergroup')->distinct()->pluck('groupname'))
                ->map(fn ($group) => trim((string) $group))
                ->filter()
                ->unique()
                ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('RADIUS group listing failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Group yang dipakai voucher tapi belum punya satu pun baris policy.
     *
     * Ini kekeliruan paling mahal di integrasi ini justru karena tidak menimbulkan
     * error: Access-Request-nya dijawab Access-Accept tanpa atribut apa pun,
     * mahasiswa tetap bisa login, dan batas kecepatan yang disangka berlaku tidak
     * pernah ada. Halaman voucher dan halaman paket memakai daftar ini untuk
     * mengatakannya sebelum ada yang mengeluh WiFi-nya "kok kencang sekali".
     *
     * Tidak pernah melempar exception: dipakai sebagai prop halaman.
     *
     * @return array<int,string>
     */
    public function groupsWithoutPolicy(): array
    {
        if (! $this->configured()) {
            return [];
        }

        try {
            $db = $this->connection();

            $withPolicy = Collection::make($db->table('radgroupreply')->distinct()->pluck('groupname'))
                ->merge($db->table('radgroupcheck')->distinct()->pluck('groupname'))
                ->map(fn ($group) => trim((string) $group))
                ->filter()
                ->all();

            return Collection::make($db->table('radusergroup')->distinct()->pluck('groupname'))
                ->map(fn ($group) => trim((string) $group))
                ->filter()
                ->unique()
                ->reject(fn (string $group) => in_array($group, $withPolicy, true))
                ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();
        } catch (\Throwable $e) {
            Log::warning('RADIUS policy check failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Isi setiap paket hotspot, apa adanya dari database RADIUS.
     *
     * Satu "paket" tidak tinggal di satu tabel: policy-nya di radgroupreply,
     * syaratnya di radgroupcheck, dan jumlah pemakainya di radusergroup. Halaman
     * Paket Hotspot butuh ketiganya sekaligus, jadi diambil tiga query lalu
     * dikelompokkan di PHP — bukan satu query per paket.
     *
     * Group yang hanya muncul di radusergroup tetap ikut, dengan has_policy false.
     * Itu bukan kelengkapan yang manis-manis saja: group tanpa policy adalah
     * keadaan yang justru harus terlihat, bukan hilang dari daftar.
     *
     * Tidak pernah melempar exception: dipakai sebagai prop halaman.
     *
     * @return array<int,array<string,mixed>>
     */
    public function packages(): array
    {
        if (! $this->configured()) {
            return [];
        }

        try {
            $db = $this->connection();

            $reply = Collection::make($db->table('radgroupreply')
                ->select('groupname', 'attribute', 'op', 'value')->get())
                ->groupBy(fn ($row) => trim((string) $row->groupname));

            $check = Collection::make($db->table('radgroupcheck')
                ->select('groupname', 'attribute', 'op', 'value')->get())
                ->groupBy(fn ($row) => trim((string) $row->groupname));

            // Dihitung di server, bukan dengan menarik seluruh radusergroup:
            // tabel itu tumbuh sebesar jumlah mahasiswa, dan halaman ini hanya
            // butuh angkanya.
            $members = Collection::make($db->table('radusergroup')
                ->select('groupname')
                ->selectRaw('count(distinct username) as total')
                ->groupBy('groupname')->get())
                ->mapWithKeys(fn ($row) => [trim((string) $row->groupname) => (int) $row->total]);

            return $reply->keys()
                ->merge($check->keys())
                ->merge($members->keys())
                ->map(fn ($name) => trim((string) $name))
                ->filter()
                ->unique()
                ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->map(fn (string $name) => $this->describePackage(
                    $name,
                    Collection::make($reply->get($name, [])),
                    Collection::make($check->get($name, [])),
                    (int) ($members->get($name) ?? 0),
                ))
                ->all();
        } catch (\Throwable $e) {
            Log::warning('RADIUS package listing failed: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Satu baris daftar paket: yang bisa diubah formulir, dan yang cuma dibaca.
     *
     * `extra` dan `check` sengaja dipisahkan dari atribut terkelola. Keduanya nyata
     * berlaku di RADIUS — operator harus melihatnya — tapi keduanya juga di luar
     * jangkauan formulir ini: `extra` karena bukan milik CIMS, `check` karena syarat
     * login bukan hal yang pantas berubah karena seseorang menyimpan formulir
     * kecepatan.
     *
     * Satu pengecualian: Simultaneous-Use bernilai angka diangkat menjadi
     * `sharing_limit` dan tidak lagi ikut di `check`, karena ia memang bisa disunting
     * di halaman ini. Nilai yang bukan angka positif tetap tinggal di `check` —
     * FreeRADIUS pun tidak bisa menghitung apa pun dari nilai seperti itu, jadi
     * menampilkannya sebagai batas hanya akan melaporkan pembatasan yang tidak
     * pernah berlaku.
     *
     * @param  Collection<int,object>  $reply
     * @param  Collection<int,object>  $check
     * @return array<string,mixed>
     */
    protected function describePackage(string $name, Collection $reply, Collection $check, int $members): array
    {
        $managed = [];
        $extra = [];

        foreach ($reply as $row) {
            $attribute = trim((string) $row->attribute);

            // Yang teratas menang bila satu atribut kebetulan tercatat dua kali.
            // Menyimpan formulir merapikannya jadi satu baris.
            if (in_array($attribute, self::MANAGED_GROUP_REPLY, true) && ! isset($managed[$attribute])) {
                $managed[$attribute] = (string) $row->value;

                continue;
            }

            $extra[] = [
                'attribute' => $attribute,
                'op' => trim((string) $row->op),
                'value' => (string) $row->value,
            ];
        }

        $limit = null;
        $conditions = [];

        foreach ($check as $row) {
            $attribute = trim((string) $row->attribute);
            $value = trim((string) $row->value);

            if ($attribute === 'Simultaneous-Use' && $limit === null && ctype_digit($value) && (int) $value > 0) {
                $limit = (int) $value;

                continue;
            }

            $conditions[] = [
                'attribute' => $attribute,
                'op' => trim((string) $row->op),
                'value' => (string) $row->value,
            ];
        }

        $rate = $managed['Mikrotik-Rate-Limit'] ?? null;
        $mikrotikGroup = trim((string) ($managed['Mikrotik-Group'] ?? ''));

        return [
            'name' => $name,
            'rate_limit' => $rate,
            'speed' => MikrotikRateLimit::parse($rate),
            'session_timeout' => $this->seconds($managed['Session-Timeout'] ?? null),
            'idle_timeout' => $this->seconds($managed['Idle-Timeout'] ?? null),
            'interim_interval' => $this->seconds($managed['Acct-Interim-Interval'] ?? null),
            'mikrotik_group' => $mikrotikGroup !== '' ? $mikrotikGroup : null,
            'sharing_limit' => $limit,
            'extra' => $extra,
            'check' => $conditions,
            'members' => $members,
            'has_policy' => $reply->isNotEmpty() || $check->isNotEmpty(),
        ];
    }

    /** Detik dari nilai radgroupreply; null bila kosong, nol, atau bukan angka. */
    protected function seconds(?string $value): ?int
    {
        $value = trim((string) $value);

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** Jumlah NIM yang terdaftar di group ini. */
    public function packageMembers(string $groupname): int
    {
        return (int) $this->connection()->table('radusergroup')
            ->where('groupname', trim($groupname))
            ->distinct()
            ->count('username');
    }

    /**
     * Tulis policy satu paket. Atribut yang dikirim kosong berarti "tanpa batas
     * itu" dan barisnya dihapus, bukan disimpan sebagai 0 — `Session-Timeout := 0`
     * memutus sesi seketika, dan itu bukan yang dimaksud operator ketika ia
     * mengosongkan kolom.
     *
     * Yang dihapus hanya MANAGED_GROUP_REPLY; baris lain di group ini tidak pernah
     * tersentuh. Satu transaksi, supaya paket tidak pernah setengah tersimpan —
     * rate limit baru dengan session-timeout lama yang seharusnya sudah hilang
     * adalah paket yang tidak pernah diminta siapa pun.
     *
     * @param  array<string,string|null>  $attributes
     */
    public function savePackage(string $groupname, array $attributes): void
    {
        $groupname = trim($groupname);

        $rows = [];

        foreach (self::MANAGED_GROUP_REPLY as $attribute) {
            $value = trim((string) ($attributes[$attribute] ?? ''));

            if ($value === '') {
                continue;
            }

            $rows[] = [
                'groupname' => $groupname,
                'attribute' => $attribute,
                'op' => ':=',
                'value' => $value,
            ];
        }

        $this->connection()->transaction(function () use ($groupname, $rows) {
            $db = $this->connection();

            $db->table('radgroupreply')
                ->where('groupname', $groupname)
                ->whereIn('attribute', self::MANAGED_GROUP_REPLY)
                ->delete();

            if ($rows !== []) {
                $db->table('radgroupreply')->insert($rows);
            }
        });
    }

    /**
     * Batas sesi bersamaan satu paket, atau null bila tidak dibatasi.
     *
     * Yang dibaca radgroupcheck, bukan radcheck: yang di sini berlaku untuk seluruh
     * anggota paket, yang di radcheck cuma untuk satu NIM dan justru pengecualian
     * terhadap angka ini.
     */
    public function sharingLimit(string $groupname): ?int
    {
        $value = trim((string) $this->connection()->table('radgroupcheck')
            ->where('groupname', trim($groupname))
            ->where('attribute', 'Simultaneous-Use')
            ->value('value'));

        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Setel batas sesi bersamaan satu paket; null berarti tanpa batas.
     *
     * Tidak menulis apa pun bila keadaannya sudah sama, dan itu bukan penghematan
     * query. radgroupcheck adalah satu-satunya tabel yang izin CIMS-nya masih bisa
     * SELECT saja di server yang sudah berjalan — dulu memang begitu yang
     * diberikan. Menyimpan formulir kecepatan tidak boleh gagal gara-gara kolom
     * yang operator sama sekali tidak sentuh ikut ditulis ulang.
     *
     * Yang dihapus hanya MANAGED_GROUP_CHECK. Auth-Type, Expiration, dan syarat
     * login lain di group yang sama tidak pernah tersentuh: masing-masing bisa
     * menolak seluruh anggota paket, dan tidak ada yang meminta itu terjadi karena
     * seseorang mengubah batas perangkat.
     *
     * Satu transaksi, walau paling banyak dua pernyataan: di antara DELETE dan
     * INSERT ada satu saat group ini tidak punya batas sama sekali, dan pada saat
     * itulah Access-Request yang kebetulan masuk akan diterima tanpa batas.
     *
     * @return bool true bila ada yang benar-benar berubah di RADIUS
     */
    public function saveSharingLimit(string $groupname, ?int $limit): bool
    {
        $groupname = trim($groupname);
        $db = $this->connection();

        $current = $db->table('radgroupcheck')
            ->where('groupname', $groupname)
            ->whereIn('attribute', self::MANAGED_GROUP_CHECK)
            ->get(['op', 'value']);

        if ($this->sameLimit($current, $limit)) {
            return false;
        }

        $db->transaction(function () use ($db, $groupname, $limit) {
            $db->table('radgroupcheck')
                ->where('groupname', $groupname)
                ->whereIn('attribute', self::MANAGED_GROUP_CHECK)
                ->delete();

            if ($limit !== null) {
                $db->table('radgroupcheck')->insert([
                    'groupname' => $groupname,
                    'attribute' => 'Simultaneous-Use',
                    'op' => ':=',
                    'value' => (string) $limit,
                ]);
            }
        });

        return true;
    }

    /**
     * Apakah baris yang ada sudah menyatakan batas ini — persis, bukan sekadar
     * nilainya.
     *
     * Dua baris kembar dan operator '=' juga berarti belum sama. Pada check item,
     * '=' cuma menambahkan bila atributnya belum ada, jadi baris seperti itu bisa
     * kalah dan pembatasannya tidak pernah berlaku; menyimpan ulang merapikannya
     * menjadi satu baris ':='.
     *
     * @param  Collection<int,object>  $rows
     */
    protected function sameLimit(Collection $rows, ?int $limit): bool
    {
        if ($limit === null) {
            return $rows->isEmpty();
        }

        return $rows->count() === 1
            && trim((string) $rows->first()->op) === ':='
            && trim((string) $rows->first()->value) === (string) $limit;
    }

    /**
     * Apa yang menentukan aman-tidaknya membatasi sesi bersamaan.
     *
     * Simultaneous-Use tidak menghitung perangkat yang benar-benar online. Ia
     * menghitung BARIS radacct yang belum ditutup, dan selisih antara keduanya —
     * sesi yatim: HP mati, router reboot, Accounting-Stop hilang di jalan — jatuh
     * sebagai penolakan login kepada mahasiswa yang tidak melakukan apa pun. Tanpa
     * satu pun pesan error di sisi operator, karena dari sudut pandang FreeRADIUS
     * pembatasannya bekerja dengan benar.
     *
     * Karena itu angka-angka ini harus ada di layar tempat batasnya dinyalakan,
     * bukan hanya di radius:doctor. `accounting` false berarti arah bahayanya justru
     * berlawanan: tidak ada yang tercatat, jadi batasnya tidak akan menolak siapa
     * pun dan rasa aman yang didapat operator palsu.
     *
     * `overrides` adalah baris Simultaneous-Use di radcheck — batas milik satu NIM,
     * yang berlaku terlepas dari angka paket dan karena itu bisa membuat laporan
     * halaman ini tidak berlaku untuk sebagian mahasiswa.
     *
     * Tidak pernah melempar exception: dipakai sebagai prop halaman.
     *
     * @return array{error:?string,accounting:bool,open:int,stale:int,stale_after_minutes:int,shared:int,overrides:int}
     */
    public function sharingReadiness(): array
    {
        $sessions = $this->activeSessions(1);

        $readiness = [
            'error' => $sessions['error'],
            'accounting' => false,
            'open' => (int) $sessions['total'],
            'stale' => (int) $sessions['stale'],
            'stale_after_minutes' => self::STALE_AFTER_MINUTES,
            'shared' => count($sessions['shared']),
            'overrides' => 0,
        ];

        if (filled($readiness['error'])) {
            return $readiness;
        }

        try {
            $db = $this->connection();

            $readiness['accounting'] = (int) $db->table('radacct')->count() > 0;
            $readiness['overrides'] = (int) $db->table('radcheck')
                ->where('attribute', 'Simultaneous-Use')
                ->distinct()
                ->count('username');
        } catch (\Throwable $e) {
            Log::warning('RADIUS sharing readiness failed: '.$e->getMessage());

            $readiness['error'] = $e->getMessage();
        }

        return $readiness;
    }

    /**
     * Hapus policy satu paket.
     *
     * Di sini SELURUH baris radgroupreply group itu dihapus — termasuk yang di luar
     * MANAGED_GROUP_REPLY. Perbedaan dengan savePackage() itu disengaja: menyimpan
     * formulir bisa terjadi tanpa sengaja, menghapus paket tidak, dan meninggalkan
     * separuh policy justru membuat group itu tetap terlihat "punya isi" padahal
     * paketnya sudah dianggap tidak ada.
     *
     * Keanggotaan (radusergroup) tidak disentuh: itu milik voucher, dan pemanggil
     * yang menolak penghapusan selama masih ada pemakainya.
     *
     * Di radgroupcheck yang dihapus justru hanya MANAGED_GROUP_CHECK — kebalikan
     * dari radgroupreply, dan juga disengaja. Batas perangkat ditulis halaman ini,
     * jadi ia ikut pergi bersama paketnya; Auth-Type dan syarat login lain bisa
     * milik konfigurasi lain di database yang sama, dan menghapusnya karena
     * seseorang membereskan daftar paket adalah cara memutus layanan yang tidak
     * pernah diminta. Sisanya dihitung dan dilaporkan supaya halaman bisa menyebut
     * apa yang masih tertinggal.
     *
     * @return array{reply:int,check:int,limit:int}
     */
    public function deletePackage(string $groupname): array
    {
        $groupname = trim($groupname);
        $db = $this->connection();

        return [
            'reply' => (int) $db->table('radgroupreply')->where('groupname', $groupname)->delete(),
            'limit' => (int) $db->table('radgroupcheck')->where('groupname', $groupname)
                ->whereIn('attribute', self::MANAGED_GROUP_CHECK)->delete(),
            'check' => (int) $db->table('radgroupcheck')->where('groupname', $groupname)
                ->whereNotIn('attribute', self::MANAGED_GROUP_CHECK)->count(),
        ];
    }
}
