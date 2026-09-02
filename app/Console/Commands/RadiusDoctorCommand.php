<?php

namespace App\Console\Commands;

use App\Models\HotspotVoucher;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pemeriksaan read-only database FreeRADIUS, sebelum CIMS menulis sebaris pun.
 *
 * Tujuan voucher hotspot berpindah: dari /ip/hotspot/user di tiap router ke satu
 * database RADIUS. Database itu bukan milik CIMS — skemanya dibuat FreeRADIUS,
 * isinya mungkin sudah dipakai konfigurasi lain, dan servernya ada di seberang
 * jaringan. Tiga hal itulah yang paling sering gagal, dan ketiganya lebih baik
 * ditemukan di sini daripada saat operator menekan tombol Terapkan:
 *
 *   1. Koneksi & hak akses. MariaDB bawaan Ubuntu hanya mendengar 127.0.0.1,
 *      jadi CIMS yang berada di server lain tidak akan bisa masuk sampai
 *      bind-address dan GRANT-nya dibereskan.
 *   2. Bentuk skema. Tabel dan kolom yang dipakai CIMS harus benar-benar ada.
 *   3. Isi pendukung. Group paket harus punya policy di radgroupreply, dan router
 *      hotspot harus terdaftar di tabel nas — kalau tidak, Access-Request dari
 *      router diabaikan FreeRADIUS dan mahasiswa tetap gagal login meski baris
 *      radcheck-nya sudah benar.
 *
 * Perintah ini tidak pernah menulis. Aman dijalankan berulang kali.
 */
class RadiusDoctorCommand extends Command
{
    protected $signature = 'radius:doctor';

    protected $description = 'Periksa koneksi, skema, dan isi pendukung database FreeRADIUS (read-only)';

    /** Tabel yang dibaca/ditulis CIMS, beserta kolom yang benar-benar dipakai. */
    protected const REQUIRED = [
        'radcheck' => ['username', 'attribute', 'op', 'value'],
        'radreply' => ['username', 'attribute', 'op', 'value'],
        'radusergroup' => ['username', 'groupname', 'priority'],
        'radgroupcheck' => ['groupname', 'attribute', 'op', 'value'],
        'radgroupreply' => ['groupname', 'attribute', 'op', 'value'],
        'nas' => ['nasname', 'shortname', 'type'],
    ];

    /** Hanya dibaca untuk laporan; tidak adanya bukan penghalang. */
    protected const OPTIONAL = ['radacct', 'radpostauth'];

    /** Tabel yang butuh izin tulis dari CIMS; sisanya cukup SELECT. */
    protected const WRITE_TABLES = ['radcheck', 'radreply', 'radusergroup'];

    protected bool $failed = false;

    /** @var array<int,string> */
    protected array $notes = [];

    public function handle(): int
    {
        $name = (string) (config('services.hotspot.radius.connection') ?: 'radius');
        $config = config("database.connections.{$name}");

        if (! is_array($config)) {
            $this->error("Connection '{$name}' tidak terdaftar di config/database.php.");

            return self::FAILURE;
        }

        $this->table(['Yang diperiksa', 'Nilai'], [
            ['Connection', $name.' ('.($config['driver'] ?? '?').')'],
            ['Server', ($config['host'] ?? '-').':'.($config['port'] ?? '-')],
            ['Database', $config['database'] ?? '-'],
            ['User DB', filled($config['username'] ?? null) ? $config['username'] : 'BELUM DIISI'],
        ]);

        if ($this->missingEnv($config)) {
            return self::FAILURE;
        }

        $db = DB::connection($name);
        $started = microtime(true);

        try {
            $db->getPdo();
        } catch (\Throwable $e) {
            $this->error('Tidak bisa tersambung: '.$e->getMessage());
            $this->connectionHints($config, $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Tersambung dalam '.(int) round((microtime(true) - $started) * 1000).' ms.');
        $this->serverFacts($db);
        $this->checkSchema($db, $name);
        $this->checkPrivileges($db, $config);
        $this->checkGroups($db, $name);
        $this->checkNas($db, $name);
        $this->checkExisting($db, $name);

        return $this->summary();
    }

    /** Nilai .env yang tanpa itu tidak ada gunanya mencoba menyambung. */
    protected function missingEnv(array $config): bool
    {
        $missing = collect([
            'RADIUS_DB_HOST' => $config['host'] ?? null,
            'RADIUS_DB_DATABASE' => $config['database'] ?? null,
            'RADIUS_DB_USERNAME' => $config['username'] ?? null,
        ])->filter(fn ($value) => blank($value))->keys();

        if ($missing->isEmpty()) {
            return false;
        }

        $this->error('Belum lengkap di .env: '.$missing->implode(', '));
        $this->newLine();
        $this->line('Tambahkan blok ini ke .env — isi nilainya langsung di file, jangan lewat chat:');
        $this->line('  RADIUS_DB_HOST=<ip-server-radius>');
        $this->line('  RADIUS_DB_PORT=3306');
        $this->line('  RADIUS_DB_DATABASE=radius');
        $this->line('  RADIUS_DB_USERNAME=<user-mysql-untuk-cims>');
        $this->line('  RADIUS_DB_PASSWORD=<password-user-itu>');
        $this->line('  HOTSPOT_RADIUS_DEFAULT_GROUP=mahasiswa');
        $this->newLine();
        $this->line('Lalu: php artisan config:clear && php artisan radius:doctor');

        return true;
    }

    /**
     * Lapisan mana yang rusak, dibaca dari pesan PDO-nya.
     *
     * Tiga kegagalan yang paling sering terjadi terbaca sama oleh operator —
     * "tidak bisa tersambung" — padahal yang harus dibereskan berbeda, dan pesan
     * PDO sudah cukup untuk memisahkannya:
     *
     *   - paket DITOLAK dijawab kernel dalam milidetik → jaringannya tembus,
     *     yang salah listener MariaDB-nya (bind-address, service mati, port);
     *   - paket DIBUANG membuat CIMS menunggu sampai batas waktu → firewall atau
     *     rute, dan MariaDB belum tentu salah apa pun;
     *   - 'Access denied' hanya mungkin SETELAH TCP handshake berhasil → jaringan
     *     dan listener sudah beres, tinggal user/host/password.
     *
     * Karena itu urutan sarannya tidak boleh tetap. Menyarankan "betulkan
     * bind-address" pada sebuah timeout membuat operator membongkar konfigurasi
     * MariaDB yang sudah benar, sementara paketnya tidak pernah sampai ke sana.
     */
    public static function failureClass(string $error): string
    {
        $error = strtolower($error);

        return match (true) {
            str_contains($error, 'access denied') => 'auth',
            str_contains($error, 'unknown database') => 'database',
            str_contains($error, 'getaddrinfo'),
            str_contains($error, 'php_network_getaddresses'),
            str_contains($error, 'unknown mysql server host') => 'host',
            str_contains($error, 'refused') => 'refused',
            str_contains($error, 'timed out'),
            str_contains($error, 'timeout'),
            str_contains($error, 'did not properly respond'),
            str_contains($error, 'failed to respond'),
            str_contains($error, 'no route to host'),
            str_contains($error, 'network is unreachable') => 'network',
            default => 'unknown',
        };
    }

    /**
     * Saran perbaikan, diurutkan menurut kelas kegagalannya — lihat failureClass().
     * Semuanya dibereskan di server RADIUS atau di .env, bukan di kode CIMS.
     */
    protected function connectionHints(array $config, string $error): void
    {
        $user = $config['username'] ?: 'cims';
        $database = $config['database'] ?: 'radius';

        $host = filled($config['host'] ?? null) ? $config['host'] : '<ip-server-radius>';
        $port = filled($config['port'] ?? null) ? $config['port'] : 3306;

        $this->newLine();

        match (self::failureClass($error)) {
            'network' => $this->networkHints($host, $port),
            'refused' => $this->refusedHints($host, $port),
            'auth' => $this->authHints($user, $database),
            'database' => $this->databaseHints($user, $database),
            'host' => $this->hostHints($host),
            default => $this->genericHints($user, $database),
        };

        $this->newLine();
        $this->line('Jangan buka '.$port.' ke internet: radcheck menyimpan password mahasiswa apa adanya.');
    }

    /**
     * Paket dibuang di jalan. Yang perlu dibereskan ada di antara kedua server,
     * bukan di MariaDB — dan kalimat terakhir menutup salah paham yang mahal:
     * doctor yang dijalankan dari laptop dev memang harus timeout, karena user
     * MySQL-nya dikunci ke IP server CIMS.
     */
    protected function networkHints(string $host, int|string $port): void
    {
        $this->warn('Paket ke '.$host.':'.$port.' DIBUANG, bukan ditolak.');
        $this->line('Koneksi yang ditolak dijawab kernel dalam hitungan milidetik; yang dibuang');
        $this->line('membuat CIMS menunggu sampai batas waktu. Jadi MariaDB belum tentu salah.');
        $this->newLine();
        $this->line('1) Firewall menutup '.$port.' — di server RADIUS, atau di antara keduanya.');
        $this->line('   Di server RADIUS: ufw allow from <ip-server-cims> to any port '.$port.' proto tcp');
        $this->newLine();
        $this->line('2) Tidak ada rute atau VLAN dari server CIMS ke '.$host.'.');
        $this->line('   Dari server CIMS: ip route get '.$host);
        $this->newLine();
        $this->line('3) MariaDB tidak berjalan: systemctl status mariadb');
        $this->newLine();
        $this->line('Uji lapisan jaringannya sendiri, dari server CIMS:');
        $this->line('   nc -vz '.$host.' '.$port);
        $this->line('Kalau nc berhasil tapi perintah ini tetap timeout, berarti perintah ini');
        $this->line('dijalankan dari mesin lain — laptop dev, bukan server CIMS. User MySQL CIMS');
        $this->line('dikunci ke IP server CIMS, jadi radius:doctor harus dijalankan di sana.');
    }

    /** Ditolak cepat berarti jaringannya sudah tembus; yang belum ada listener-nya. */
    protected function refusedHints(string $host, int|string $port): void
    {
        $this->warn('Koneksi DITOLAK cepat — jaringannya tembus, listener-nya yang belum ada.');
        $this->newLine();
        $this->line('1) MariaDB hanya mendengar localhost.');
        $this->line('   /etc/mysql/mariadb.conf.d/50-server.cnf → bind-address = '.$host);
        $this->line('   lalu: systemctl restart mariadb');
        $this->line('   Periksa: ss -lntp | grep '.$port);
        $this->newLine();
        $this->line('2) MariaDB mati: systemctl status mariadb');
        $this->newLine();
        $this->line('3) RADIUS_DB_PORT tidak sama dengan port yang didengar MariaDB.');
    }

    /**
     * 'Access denied' adalah kabar setengah baik: TCP handshake-nya berhasil, jadi
     * firewall dan bind-address sudah tidak perlu disentuh lagi.
     */
    protected function authHints(string $user, string $database): void
    {
        $this->warn('Jaringan sudah beres — ini ditolak MariaDB, bukan firewall.');
        $this->line('Yang salah salah satu dari tiga: nama user, host asalnya, atau password.');
        $this->newLine();
        $this->line("1) User '{$user}' belum ada untuk host asal koneksi ini.");
        $this->line("   Di server RADIUS: SELECT user, host FROM mysql.user WHERE user = '{$user}';");
        $this->line("   Host-nya harus IP server CIMS — 'localhost' tidak berlaku dari server lain.");
        $this->newLine();
        $this->line('2) RADIUS_DB_PASSWORD berbeda dengan yang tercatat di MariaDB.');
        $this->line('   Setelah memperbaiki .env: php artisan config:clear');
        $this->newLine();
        $this->line('3) User-nya ada, tapi belum punya izin di database ini:');
        $this->grantBlock($user, $database);
    }

    /**
     * Izin seminimal mungkin. CIMS menulis tiga tabel dan membaca sisanya; tanpa
     * CREATE/ALTER/DROP, MariaDB sendiri yang menjamin skema RADIUS tidak berubah
     * — bukan kesepakatan di kode. `GRANT ALL ON radius.*` justru memberikannya.
     */
    protected function grantBlock(string $user, string $database): void
    {
        $this->line("   CREATE USER '{$user}'@'<ip-server-cims>' IDENTIFIED BY '<password>';");
        $this->line('   GRANT SELECT, INSERT, UPDATE, DELETE ON `'.$database.'`.radcheck');
        $this->line("     TO '{$user}'@'<ip-server-cims>';   -- ulangi untuk radreply & radusergroup");
        $this->line('   GRANT SELECT ON `'.$database.'`.radgroupcheck TO ...  -- juga radgroupreply,');
        $this->line('     radacct, radpostauth, nas');
        $this->line('   FLUSH PRIVILEGES;');
    }

    /**
     * Login lolos tapi database tidak terlihat. MariaDB menyembunyikan database
     * yang tidak ada izinnya, jadi "hilang" dan "tidak diizinkan" terlihat sama.
     */
    protected function databaseHints(string $user, string $database): void
    {
        $this->warn("Login berhasil, tapi database '{$database}' tidak terlihat oleh user ini.");
        $this->newLine();
        $this->line('1) Nama di RADIUS_DB_DATABASE salah.');
        $this->line('   Di server RADIUS: SHOW DATABASES;');
        $this->newLine();
        $this->line("2) User '{$user}' belum punya izin apa pun di database itu — MariaDB");
        $this->line('   menyembunyikan yang tidak diizinkan, jadi terlihat seperti tidak ada.');
        $this->grantBlock($user, $database);
    }

    /** Belum sampai ke lapisan TCP: namanya sendiri yang tidak bisa diterjemahkan. */
    protected function hostHints(string $host): void
    {
        $this->warn("Nama host '{$host}' tidak bisa diterjemahkan menjadi IP.");
        $this->newLine();
        $this->line('1) Salah tulis di RADIUS_DB_HOST.');
        $this->line('2) Pakai IP, bukan hostname — satu lapis lagi yang bisa gagal, tanpa manfaat di sini.');
    }

    /** Kegagalan yang tidak dikenali: kembali ke tiga penyebab yang paling sering. */
    protected function genericHints(string $user, string $database): void
    {
        $this->warn('Penyebab paling sering:');
        $this->newLine();
        $this->line('1) MariaDB di server RADIUS hanya mendengar localhost.');
        $this->line('   /etc/mysql/mariadb.conf.d/50-server.cnf → bind-address = <ip-lan-server-radius>');
        $this->line('   lalu: systemctl restart mariadb');
        $this->newLine();
        $this->line('2) User MySQL untuk CIMS belum ada, atau host-nya tidak mengizinkan IP CIMS.');
        $this->grantBlock($user, $database);
        $this->newLine();
        $this->line('3) Firewall server RADIUS menutup 3306.');
        $this->line('   ufw allow from <ip-server-cims> to any port 3306 proto tcp');
    }

    /** Versi server dan identitas login, supaya jelas ini benar-benar DB yang dituju. */
    protected function serverFacts(ConnectionInterface $db): void
    {
        try {
            $row = (array) $db->selectOne('select version() as version, current_user() as who, database() as db');
            $this->line('  Versi server  : '.($row['version'] ?? '?'));
            $this->line('  Login sebagai : '.($row['who'] ?? '?').' → database '.($row['db'] ?? '?'));
        } catch (\Throwable) {
            // Driver non-MySQL (sqlite saat test) tidak punya fungsi-fungsi ini.
        }

        $this->newLine();
    }

    /** Tabel dan kolom yang dipakai CIMS harus ada sebelum satu baris pun ditulis. */
    protected function checkSchema(ConnectionInterface $db, string $name): void
    {
        $schema = Schema::connection($name);
        $rows = [];

        foreach (self::REQUIRED as $table => $columns) {
            if (! $schema->hasTable($table)) {
                $rows[] = [$table, 'wajib', 'TIDAK ADA', '-'];
                $this->failed = true;
                $this->notes[] = "Tabel {$table} tidak ada — skema FreeRADIUS belum lengkap.";

                continue;
            }

            $missing = array_values(array_diff($columns, $schema->getColumnListing($table)));

            if ($missing !== []) {
                $this->failed = true;
                $this->notes[] = "Tabel {$table} kekurangan kolom: ".implode(', ', $missing).'.';
            }

            $rows[] = [
                $table,
                'wajib',
                $missing === [] ? 'ada, kolom lengkap' : 'kolom hilang: '.implode(', ', $missing),
                $this->rowCount($db, $table),
            ];
        }

        foreach (self::OPTIONAL as $table) {
            $rows[] = [
                $table,
                'opsional',
                $schema->hasTable($table) ? 'ada' : 'tidak ada',
                $schema->hasTable($table) ? $this->rowCount($db, $table) : '-',
            ];
        }

        // Adanya userinfo/operators berarti databasenya juga dipakai daloRADIUS,
        // dan ada UI lain yang bisa mengubah baris yang sama dengan CIMS.
        $dalo = collect(['userinfo', 'operators'])->filter(fn ($t) => $schema->hasTable($t));

        $rows[] = ['(flavor)', '-', $dalo->isEmpty()
            ? 'FreeRADIUS rlm_sql stok, tanpa daloRADIUS'
            : 'ada tabel daloRADIUS: '.$dalo->implode(', '), '-'];

        if ($dalo->isNotEmpty()) {
            $this->notes[] = 'Database ini juga dipakai daloRADIUS — perubahan dari UI itu bisa '
                .'bertabrakan dengan tulisan CIMS.';
        }

        $this->table(['Tabel', 'Status', 'Keadaan', 'Baris'], $rows);
    }

    protected function rowCount(ConnectionInterface $db, string $table): string
    {
        try {
            return number_format($db->table($table)->count(), 0, ',', '.');
        } catch (\Throwable) {
            return 'tidak bisa dibaca';
        }
    }

    /**
     * Hak akses tidak bisa dibuktikan tanpa menulis, jadi yang dilaporkan adalah
     * apa yang diakui MySQL sendiri. Kekurangan izin hanya jadi catatan, bukan
     * kegagalan: GRANT lewat *.* tidak selalu terlihat di ketiga view ini.
     */
    protected function checkPrivileges(ConnectionInterface $db, array $config): void
    {
        $granted = $this->grantedPrivileges($db, (string) ($config['database'] ?? ''));

        if ($granted === null) {
            return;
        }

        $rows = [];

        foreach (array_keys(self::REQUIRED) as $table) {
            $need = in_array($table, self::WRITE_TABLES, true)
                ? ['SELECT', 'INSERT', 'UPDATE', 'DELETE']
                : ['SELECT'];

            $have = array_merge($granted['*'] ?? [], $granted[$table] ?? []);
            $missing = array_values(array_diff($need, $have));

            if ($missing !== []) {
                $this->notes[] = "Izin {$table} sepertinya kurang: ".implode(', ', $missing).'.';
            }

            $rows[] = [
                $table,
                implode(', ', $need),
                $missing === [] ? 'lengkap' : 'kurang: '.implode(', ', $missing),
            ];
        }

        $this->table(['Tabel', 'Izin yang dibutuhkan CIMS', 'Keadaan'], $rows);
    }

    /**
     * Izin milik user yang sedang login. Kunci '*' berarti berlaku untuk seluruh
     * tabel (grant tingkat global atau tingkat database).
     *
     * @return array<string,array<int,string>>|null null bila driver tidak punya information_schema
     */
    protected function grantedPrivileges(ConnectionInterface $db, string $database): ?array
    {
        try {
            $wide = collect($db->select('select privilege_type from information_schema.user_privileges'))
                ->merge($db->select(
                    'select privilege_type from information_schema.schema_privileges where table_schema = ?',
                    [$database]
                ))
                ->pluck('privilege_type')
                ->map(fn ($p) => strtoupper((string) $p))
                ->unique()
                ->values()
                ->all();

            $perTable = collect($db->select(
                'select table_name, privilege_type from information_schema.table_privileges where table_schema = ?',
                [$database]
            ))->groupBy(fn ($row) => strtolower((string) ($row->table_name ?? $row->TABLE_NAME ?? '')))
                ->map(fn ($rows) => $rows->pluck('privilege_type')
                    ->map(fn ($p) => strtoupper((string) $p))->unique()->values()->all())
                ->all();

            return ['*' => $wide] + $perTable;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Setiap profile yang dipakai voucher akan menjadi groupname di radusergroup.
     * Group tanpa satu pun baris di radgroupreply/radgroupcheck bukan error — tapi
     * artinya paketnya tidak berefek: mahasiswa tetap login, tanpa batas apa pun.
     */
    protected function checkGroups(ConnectionInterface $db, string $name): void
    {
        if (! Schema::connection($name)->hasTable('radgroupreply')) {
            return;
        }

        $default = trim((string) config('services.hotspot.radius.default_group'));

        $groups = HotspotVoucher::query()
            ->distinct()
            ->pluck('profile')
            ->map(fn ($profile) => trim((string) $profile))
            ->filter()
            ->when($default !== '', fn ($list) => $list->push($default))
            ->unique()
            ->sort()
            ->values();

        if ($groups->isEmpty()) {
            $this->notes[] = 'Tidak ada profile pada voucher dan HOTSPOT_RADIUS_DEFAULT_GROUP kosong — '
                .'baris radusergroup tidak akan ditulis, jadi tidak ada paket yang menempel ke mahasiswa.';

            return;
        }

        $rows = [];

        foreach ($groups as $group) {
            $reply = (int) $db->table('radgroupreply')->where('groupname', $group)->count();
            $check = (int) $db->table('radgroupcheck')->where('groupname', $group)->count();
            $used = HotspotVoucher::where('profile', $group)->count();

            if ($reply + $check === 0) {
                $this->notes[] = "Group '{$group}' belum punya policy di radgroupreply/radgroupcheck — "
                    .'voucher yang memakainya login tanpa rate limit.';
            }

            $rows[] = [
                $group.($group === $default ? ' (default)' : ''),
                number_format($used, 0, ',', '.'),
                $reply,
                $check,
                $reply + $check === 0 ? 'POLICY KOSONG' : 'ada policy',
            ];
        }

        $this->table(['Group', 'Voucher memakai', 'radgroupreply', 'radgroupcheck', 'Keadaan'], $rows);
    }

    /**
     * FreeRADIUS membuang Access-Request dari NAS yang tidak dikenalnya. Baris
     * radcheck yang benar tetap tidak menolong kalau router hotspot belum
     * terdaftar di sini. Kolom secret sengaja tidak pernah dibaca.
     */
    protected function checkNas(ConnectionInterface $db, string $name): void
    {
        if (! Schema::connection($name)->hasTable('nas')) {
            return;
        }

        $registered = $db->table('nas')->orderBy('nasname')
            ->get(['nasname', 'shortname', 'type']);

        $this->table(['NAS terdaftar (nasname)', 'shortname', 'type'], $registered
            ->map(fn ($row) => [$row->nasname, $row->shortname ?? '-', $row->type ?? '-'])
            ->all());

        $expected = collect([
            'HOTSPOT_ROUTER_HOST' => config('services.hotspot.router_host'),
            'MIKROTIK_HOST' => config('services.mikrotik.host'),
        ])->map(fn ($host) => trim((string) $host))->filter();

        if ($expected->isEmpty()) {
            return;
        }

        $names = $registered->pluck('nasname')->map(fn ($n) => trim((string) $n))->all();
        $rows = [];

        foreach ($expected as $key => $host) {
            $found = in_array($host, $names, true);

            // 0.0.0.0/0 di tabel nas menerima NAS mana pun; itu jawaban sah,
            // walau lebih longgar daripada mendaftarkan alamat routernya.
            $wildcard = collect($names)->contains(fn ($n) => in_array($n, ['0.0.0.0/0', '0.0.0.0'], true));

            if (! $found && ! $wildcard) {
                $this->notes[] = "Router {$host} ({$key}) belum ada di tabel nas — Access-Request "
                    .'dari router itu akan diabaikan FreeRADIUS.';
            }

            $rows[] = [$key, $host, $found ? 'terdaftar' : ($wildcard ? 'tercakup 0.0.0.0/0' : 'BELUM TERDAFTAR')];
        }

        $this->table(['Sumber', 'Alamat router', 'Di tabel nas'], $rows);
    }

    /**
     * Seberapa besar tabrakan dengan isi yang sudah ada. Attribute yang bukan
     * milik CIMS dilaporkan supaya jelas ada konfigurasi lain yang hidup di
     * database yang sama — dan supaya nanti bisa dibandingkan dengan attribute
     * yang benar-benar dihapus/ditulis ulang oleh push.
     */
    protected function checkExisting(ConnectionInterface $db, string $name): void
    {
        if (! Schema::connection($name)->hasTable('radcheck')) {
            return;
        }

        $vouchers = HotspotVoucher::count();
        $usernames = (int) $db->table('radcheck')->distinct()->count('username');

        $overlap = 0;

        foreach (HotspotVoucher::pluck('nim')->chunk(500) as $chunk) {
            $overlap += (int) $db->table('radcheck')
                ->whereIn('username', $chunk->all())
                ->distinct()
                ->count('username');
        }

        $this->table(['Isi RADIUS sekarang', 'Jumlah'], [
            ['Username unik di radcheck', number_format($usernames, 0, ',', '.')],
            ['Voucher di CIMS', number_format($vouchers, 0, ',', '.')],
            ['NIM yang sudah ada di radcheck', number_format($overlap, 0, ',', '.')],
            ['Akan ditambahkan push pertama', number_format(max($vouchers - $overlap, 0), 0, ',', '.')],
        ]);

        $byAttribute = $db->table('radcheck')
            ->selectRaw('attribute, count(*) as jumlah')
            ->groupBy('attribute')
            ->orderByDesc('jumlah')
            ->get();

        if ($byAttribute->isNotEmpty()) {
            $this->table(['Attribute di radcheck', 'Jumlah baris'], $byAttribute
                ->map(fn ($row) => [$row->attribute, number_format((int) $row->jumlah, 0, ',', '.')])
                ->all());
        }
    }

    /**
     * Skema yang salah menghentikan langkah berikutnya; sisanya cukup jadi catatan
     * karena mahasiswa masih bisa login, hanya tanpa batas atau tanpa paket.
     */
    protected function summary(): int
    {
        $this->newLine();

        foreach ($this->notes as $note) {
            $this->warn('• '.$note);
        }

        if ($this->failed) {
            $this->newLine();
            $this->error('Skema RADIUS belum memenuhi syarat. Jangan lanjut menulis sampai ini beres.');

            return self::FAILURE;
        }

        $this->newLine();

        if ($this->notes !== []) {
            $this->info('Skema siap dipakai. Catatan di atas tidak menghalangi push, tapi sebaiknya '
                .'dibereskan sebelum mahasiswa dilepas ke jaringan.');
        } else {
            $this->info('Semua pemeriksaan lolos.');
        }

        return self::SUCCESS;
    }
}
