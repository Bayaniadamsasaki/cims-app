<?php

namespace App\Console\Commands;

use App\Models\HotspotVoucher;
use App\Services\RadiusService;
use App\Support\VoucherRadiusApplier;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Bandingkan isi database RADIUS dengan hotspot_vouchers, laporkan selisihnya.
 *
 * RADIUS adalah proyeksi dari hotspot_vouchers, bukan sumber kebenaran — dan
 * proyeksi bisa menyimpang: satu chunk yang gagal saat operator menutup tab,
 * baris yang diedit langsung dari mysql client, atau voucher yang dihapus
 * sementara koneksi RADIUS sedang mati. Kolom status CIMS tidak akan tahu:
 * ia hanya mencatat apa yang terjadi saat tombol Terapkan ditekan.
 *
 * Yang paling penting dari perintah ini bukan hitungan "belum diterapkan" —
 * itu sudah kelihatan di halaman voucher — melainkan dua selisih yang tidak
 * kelihatan di mana pun:
 *
 *   - voucher yang DIBLOKIR di CIMS tapi RADIUS masih menerimanya. Mahasiswa yang
 *     dikira sudah diblokir tetap bisa login, dan tidak ada satu pun tampilan
 *     yang menunjukkannya.
 *   - username di RADIUS yang tidak dikenal CIMS. Bisa jadi voucher yang gagal
 *     dicabut saat dihapus, bisa jadi milik konfigurasi lain di database yang
 *     sama. Karena tidak bisa dibedakan, --fix TIDAK PERNAH menghapusnya.
 *
 * Tanpa --fix perintah ini read-only di kedua database.
 */
class RadiusReconcileCommand extends Command
{
    protected $signature = 'radius:reconcile
                            {--fix : Tulis ulang voucher yang belum sesuai di RADIUS}
                            {--samples=10 : Jumlah contoh NIM yang ditampilkan per jenis selisih}';

    protected $description = 'Cari selisih antara voucher CIMS dan database RADIUS';

    /** NIM per putaran perbandingan. */
    protected const CHUNK = 500;

    /**
     * Jenis selisih, beserta penjelasan yang dicetak di laporan. Urutannya
     * urutan bahaya, bukan urutan abjad.
     */
    protected const KINDS = [
        'not_blocked' => 'Diblokir di CIMS, tapi RADIUS masih menerima login',
        'stale_block' => 'RADIUS menolak, padahal tidak diblokir di CIMS',
        'missing' => 'Belum ada di RADIUS',
        'password' => 'Password di RADIUS berbeda',
        'group' => 'Group (paket) di RADIUS berbeda',
        'status_stale' => 'Isi RADIUS sudah benar, tapi status CIMS belum synced',
    ];

    public function handle(RadiusService $radius, VoucherRadiusApplier $applier): int
    {
        if (! $radius->configured()) {
            $this->error('Koneksi RADIUS belum diatur. Isi RADIUS_DB_* di .env, lalu jalankan radius:doctor.');

            return self::FAILURE;
        }

        $health = $radius->health();

        if (! $health['success']) {
            $this->error('Server RADIUS tidak bisa dihubungi: '.$health['error']);
            $this->line('Jalankan `php artisan radius:doctor` untuk melihat penyebabnya.');

            return self::FAILURE;
        }

        $drift = array_fill_keys(array_keys(self::KINDS), []);
        $checked = 0;
        $defaultGroup = $radius->defaultGroup();

        HotspotVoucher::query()
            ->chunkById(self::CHUNK, function (Collection $vouchers) use ($radius, $defaultGroup, &$drift, &$checked) {
                $checked += $vouchers->count();
                $state = $this->radiusState($radius, $vouchers->pluck('nim')->all());

                foreach ($vouchers as $voucher) {
                    $kind = $this->classify($voucher, $state, $defaultGroup);

                    if ($kind !== null) {
                        $drift[$kind][] = $voucher;
                    }
                }
            });

        $orphans = $this->orphans($radius);

        // Semua jenis selisih diperbaiki dengan cara yang sama: tulis ulang barisnya
        // dari hotspot_vouchers. toRadiusRows() sudah memuat Auth-Type := Reject
        // untuk voucher yang diblokir, jadi 'not_blocked' sembuh di jalur yang sama,
        // dan 'status_stale' ikut terkoreksi karena penerapan juga menulis status.
        $repairable = Collection::make($drift)->flatten(1);

        $this->report($drift, $orphans, $checked, $repairable->count());

        if ($repairable->isEmpty() && $orphans === []) {
            $this->info('Tidak ada selisih. Isi RADIUS sama dengan hotspot_vouchers.');

            return self::SUCCESS;
        }

        if (! $this->option('fix')) {
            if ($repairable->isNotEmpty()) {
                $this->warn($repairable->count().' voucher belum sesuai. '
                    .'Jalankan ulang dengan --fix untuk menulisnya ke RADIUS.');
            }

            return self::SUCCESS;
        }

        $result = $applier->apply($repairable);

        $this->newLine();
        $this->info("Ditulis ulang ke RADIUS: {$result['ok']} voucher berhasil, {$result['failed']} gagal.");

        if ($result['failed'] > 0) {
            $this->error('Kegagalan pertama: '.$result['error']);

            return self::FAILURE;
        }

        if ($orphans !== []) {
            $this->warn(count($orphans).' username di RADIUS tidak dikenal CIMS dan sengaja TIDAK disentuh --fix. '
                .'Periksa daftar di atas sebelum menghapusnya sendiri.');
        }

        return self::SUCCESS;
    }

    /**
     * Isi RADIUS untuk sekumpulan NIM: password, penolakan, dan group.
     *
     * @param  array<int,string>  $nims
     * @return array{password:array<string,string>,reject:array<string,bool>,group:array<string,string>}
     */
    protected function radiusState(RadiusService $radius, array $nims): array
    {
        $db = $radius->connection();
        $nims = array_values(array_unique(array_map(fn ($nim) => (string) $nim, $nims)));

        $check = $db->table('radcheck')
            ->whereIn('username', $nims)
            ->whereIn('attribute', RadiusService::MANAGED_CHECK)
            ->get(['username', 'attribute', 'value']);

        $state = ['password' => [], 'reject' => [], 'group' => []];

        foreach ($check as $row) {
            if ($row->attribute === 'Cleartext-Password') {
                $state['password'][(string) $row->username] = (string) $row->value;

                continue;
            }

            if (strcasecmp((string) $row->value, 'Reject') === 0) {
                $state['reject'][(string) $row->username] = true;
            }
        }

        // Voucher hanya pernah menulis satu group per username, tapi database ini
        // bukan milik CIMS sendiri: kalau ada dua, yang priority-nya paling kecil
        // itulah yang dipakai FreeRADIUS, jadi itu yang dibandingkan.
        foreach ($db->table('radusergroup')->whereIn('username', $nims)
            ->orderByDesc('priority')->get(['username', 'groupname']) as $row) {
            $state['group'][(string) $row->username] = (string) $row->groupname;
        }

        return $state;
    }

    /**
     * Jenis selisih satu voucher, atau null bila sudah sesuai.
     *
     * @param  array{password:array<string,string>,reject:array<string,bool>,group:array<string,string>}  $state
     */
    protected function classify(HotspotVoucher $voucher, array $state, string $defaultGroup): ?string
    {
        $nim = (string) $voucher->nim;
        $blocked = $voucher->status === HotspotVoucher::STATUS_DISABLED;
        $rejected = isset($state['reject'][$nim]);
        $hasPassword = isset($state['password'][$nim]);

        // Blokir yang tidak sampai hanya berbahaya kalau passwordnya memang ada di
        // RADIUS. Tanpa password, Access-Request-nya ditolak sebagai unknown user —
        // itu kasus 'missing', bukan lubang keamanan.
        if ($blocked && $hasPassword && ! $rejected) {
            return 'not_blocked';
        }

        if ($rejected && ! $blocked) {
            return 'stale_block';
        }

        if (! $hasPassword) {
            return 'missing';
        }

        if ($state['password'][$nim] !== (string) $voucher->password) {
            return 'password';
        }

        $group = trim((string) ($voucher->profile ?: $defaultGroup));

        if ($group !== '' && ($state['group'][$nim] ?? null) !== $group) {
            return 'group';
        }

        // Voucher yang diblokir memang tidak pernah berstatus synced; isi RADIUS-nya
        // sudah sesuai, jadi tidak ada yang perlu dilaporkan.
        if ($blocked) {
            return null;
        }

        return $voucher->status === HotspotVoucher::STATUS_SYNCED ? null : 'status_stale';
    }

    /**
     * Username di radcheck yang tidak punya voucher di CIMS.
     *
     * @return array<int,string>
     */
    protected function orphans(RadiusService $radius): array
    {
        $orphans = [];

        $radius->connection()->table('radcheck')
            ->where('attribute', 'Cleartext-Password')
            ->distinct()
            ->orderBy('username')
            ->select('username')
            ->chunk(self::CHUNK, function (Collection $rows) use (&$orphans) {
                $usernames = $rows->pluck('username')->map(fn ($u) => (string) $u)->all();

                $known = HotspotVoucher::whereIn('nim', $usernames)->pluck('nim')
                    ->map(fn ($nim) => (string) $nim)->all();

                $orphans = array_merge($orphans, array_values(array_diff($usernames, $known)));
            });

        return $orphans;
    }

    /**
     * @param  array<string,array<int,HotspotVoucher>>  $drift
     * @param  array<int,string>  $orphans
     */
    protected function report(array $drift, array $orphans, int $checked, int $total): void
    {
        $samples = max(1, (int) $this->option('samples'));

        $this->table(['Yang dihitung', 'Jumlah'], [
            ['Voucher di CIMS', number_format($checked, 0, ',', '.')],
            ['Sudah sesuai di RADIUS', number_format(max(0, $checked - $total), 0, ',', '.')],
            ['Selisih', number_format($total, 0, ',', '.')],
            ['Username RADIUS tanpa voucher CIMS', number_format(count($orphans), 0, ',', '.')],
        ]);

        $rows = [];

        foreach (self::KINDS as $kind => $label) {
            if ($drift[$kind] === []) {
                continue;
            }

            $rows[] = [
                $label,
                number_format(count($drift[$kind]), 0, ',', '.'),
                Collection::make($drift[$kind])->take($samples)->pluck('nim')->implode(', ')
                    .(count($drift[$kind]) > $samples ? ', …' : ''),
            ];
        }

        if ($rows !== []) {
            $this->newLine();
            $this->table(['Jenis selisih', 'Jumlah', 'Contoh NIM'], $rows);
        }

        if ($orphans !== []) {
            $this->newLine();
            $this->table(['Username di RADIUS tanpa voucher CIMS', 'Jumlah'], [[
                implode(', ', array_slice($orphans, 0, $samples)).(count($orphans) > $samples ? ', …' : ''),
                number_format(count($orphans), 0, ',', '.'),
            ]]);
            $this->line('Baris ini bisa jadi milik konfigurasi RADIUS lain di database yang sama, '
                .'jadi CIMS tidak pernah menghapusnya sendiri.');
        }
    }
}
