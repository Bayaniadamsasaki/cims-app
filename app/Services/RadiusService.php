<?php

namespace App\Services;

use App\Models\HotspotVoucher;
use Illuminate\Database\ConnectionInterface;
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
}
