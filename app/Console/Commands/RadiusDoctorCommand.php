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
            $this->connectionHints($config);

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
     * Kegagalan koneksi dari server lain hampir selalu salah satu dari tiga ini,
     * dan ketiganya dibereskan di server RADIUS, bukan di kode CIMS.
     */
    protected function connectionHints(array $config): void
    {
        $user = $config['username'] ?: 'cims';
        $database = $config['database'] ?: 'radius';

        $this->newLine();
        $this->warn('Penyebab paling sering, urut dari yang paling sering:');
        $this->newLine();
        $this->line('1) MariaDB di server RADIUS hanya mendengar localhost.');
        $this->line('   /etc/mysql/mariadb.conf.d/50-server.cnf → bind-address = 0.0.0.0');
        $this->line('   lalu: systemctl restart mariadb');
        $this->newLine();
        $this->line('2) User MySQL untuk CIMS belum ada, atau host-nya tidak mengizinkan IP CIMS.');
        $this->line("   CREATE USER '{$user}'@'<ip-server-cims>' IDENTIFIED BY '<password>';");
        $this->line('   GRANT SELECT, INSERT, UPDATE, DELETE ON `'.$database.'`.radcheck');
        $this->line('     TO \''.$user.'\'@\'<ip-server-cims>\';   -- ulangi untuk radreply & radusergroup');
        $this->line('   GRANT SELECT ON `'.$database.'`.radgroupcheck TO ...  -- juga radgroupreply,');
        $this->line('     radacct, radpostauth, nas');
        $this->line('   FLUSH PRIVILEGES;');
        $this->newLine();
        $this->line('3) Firewall server RADIUS menutup 3306.');
        $this->line('   ufw allow from <ip-server-cims> to any port 3306 proto tcp');
        $this->newLine();
        $this->line('Jangan buka 3306 ke internet: radcheck menyimpan password mahasiswa apa adanya.');
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
