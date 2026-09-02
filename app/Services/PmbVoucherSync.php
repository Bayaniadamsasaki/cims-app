<?php

namespace App\Services;

use App\Models\HotspotVoucher;
use App\Support\HotspotVoucherWriter;
use App\Support\VoucherPassword;
use App\Support\VoucherRadiusApplier;
use Illuminate\Support\Facades\DB;

/**
 * Ubah daftar mahasiswa SISKA menjadi baris voucher hotspot, lalu tutup akses
 * NIM yang sudah tidak ada lagi di sana.
 *
 * Dipakai bersama oleh perintah `hotspot:sync-pmb` dan tombol "Tarik dari
 * SISKA" di halaman voucher, supaya keduanya menghasilkan data yang sama.
 *
 * Aturan password mengikuti janji ke mahasiswa: tanggal lahir dengan urutan
 * tanggal-bulan-tahun. Mahasiswa yang tanggal lahirnya belum terisi di SISKA
 * tetap dibuatkan voucher dengan password NIM — jumlah dan contoh NIM-nya
 * dilaporkan, karena mereka yang perlu diberi tahu passwordnya berbeda.
 *
 * Sinkronisasi ini hanya MENUTUP akses secara otomatis, tidak pernah membukanya.
 * NIM yang hilang dari PMB langsung ditolak di RADIUS; NIM yang muncul kembali
 * cuma dikembalikan ke pending, karena membuka WiFi kampus atas dasar satu
 * respons API tidak layak terjadi tanpa satu klik manusia.
 *
 * Tiga pengaman menjaga penonaktifan massal — sejak voucher pindah ke RADIUS,
 * satu keputusan di sini memutus akses di seluruh kampus, bukan di satu router:
 *
 *   1. Tarikan bersaring (prodi / pencarian / batas jumlah) tidak menonaktifkan
 *      apa pun. Pada tarikan satu prodi, "tidak ada di respons" cuma berarti
 *      bukan prodi itu, dan --force pun tidak membuat daftar seperti itu sahih.
 *   2. Respons tanpa satu pun NIM sah tidak pernah diartikan sebagai "semua
 *      mahasiswa keluar", bahkan dengan --force.
 *   3. Pada tarikan penuh, penonaktifan dibatalkan seluruhnya bila NIM dari PMB
 *      kurang dari 80% jumlah voucher PMB yang sudah ada. Itu pola respons yang
 *      terpotong di tengah paging, bukan pola mahasiswa yang lulus; operator
 *      yang yakin penurunannya nyata mengulang dengan --force.
 *
 * Voucher `manual` dan `import` — dosen, staf, tamu — tidak pernah ikut ditutup:
 * mereka memang tidak akan pernah muncul di daftar mahasiswa PMB.
 */
class PmbVoucherSync
{
    /** Contoh NIM yang ditampilkan per kategori dalam laporan. */
    protected const SAMPLES = 10;

    /** Baris per putaran saat mencari dan menutup voucher yang tidak aktif. */
    protected const CHUNK = 500;

    /**
     * Perbandingan minimal jumlah NIM dari PMB terhadap voucher `pmb` yang sudah
     * ada, sebelum penonaktifan dianggap masuk akal.
     */
    protected const DEACTIVATE_MIN_RATIO = 0.8;

    /** Isi kolom disabled_reason untuk voucher yang ditutup sinkronisasi. */
    public const DEACTIVATE_REASON = 'tidak ada di PMB';

    public function __construct(
        protected PmbStudentService $pmb,
        protected VoucherRadiusApplier $applier,
    ) {
    }

    /**
     * @param  array{prodi?:string|int|null,search?:string|null,batch_label?:string|null,profile?:string|null,server?:string|null,valid_until?:string|null,comment?:string|null,user_id?:int|null,limit?:int|null,dry_run?:bool,deactivate?:bool,force?:bool,on_page?:callable|null}  $options
     * @return array{router_host:string,total:int,created:int,updated:int,revived:int,skipped:int,by_birth_date:int,by_nim:int,nim_samples:array<int,string>,invalid_samples:array<int,string>,deactivated:int,deactivate_candidates:int,deactivate_failed:int,deactivate_error:?string,deactivate_samples:array<int,string>,deactivate_skipped:?string,dry_run:bool}
     */
    public function run(string $routerHost, array $options = []): array
    {
        $students = $this->pmb->students(
            array_filter([
                'program_studi_kode' => $options['prodi'] ?? null,
                'search' => $options['search'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
            $options['on_page'] ?? null,
        );

        if (($limit = (int) ($options['limit'] ?? 0)) > 0) {
            $students = array_slice($students, 0, $limit);
        }

        $report = [
            'router_host' => $routerHost,
            'total' => count($students),
            'created' => 0,
            'updated' => 0,
            'revived' => 0,
            'skipped' => 0,
            'by_birth_date' => 0,
            'by_nim' => 0,
            'nim_samples' => [],
            'invalid_samples' => [],
            'deactivated' => 0,
            'deactivate_candidates' => 0,
            'deactivate_failed' => 0,
            'deactivate_error' => null,
            'deactivate_samples' => [],
            'deactivate_skipped' => null,
            'dry_run' => (bool) ($options['dry_run'] ?? false),
        ];

        $writer = new HotspotVoucherWriter(
            routerHost: $routerHost,

            // Group RADIUS lebih dulu, baru HOTSPOT_DEFAULT_PROFILE yang lama:
            // kolom profile sekarang menjadi groupname di radusergroup, dan nama
            // user-profile RouterOS yang basi hanya akan menempatkan mahasiswa di
            // group yang tidak punya policy — bisa login, tanpa batas apa pun.
            defaultProfile: ($options['profile'] ?? null)
                ?: (config('services.hotspot.radius.default_group')
                    ?: (config('services.hotspot.default_profile') ?: null)),
            defaultServer: $options['server'] ?? null,
            batchLabel: $options['batch_label'] ?? null,
            userId: $options['user_id'] ?? null,
            validUntil: $options['valid_until'] ?? null,

            // PMB menyebut sebuah NIM berarti ia memang mahasiswa, dan sejak itu
            // barisnya boleh ditutup otomatis saat NIM-nya hilang dari sana.
            source: HotspotVoucher::SOURCE_PMB,
        );

        // Dry run tetap harus bisa membedakan baris baru dari baris lama, jadi
        // NIM yang sudah ada dibaca lebih dulu ketimbang ditulis lalu dibatalkan.
        $existing = $report['dry_run']
            ? $this->existingNims(array_column($students, 'nim'))
            : [];

        // NIM yang benar-benar bisa jadi username hotspot. Inilah pembanding untuk
        // mencari voucher yang sudah tidak ada di PMB — bukan $students mentah,
        // karena baris yang NIM-nya ditolak tidak pernah punya voucher.
        $seen = [];

        $write = function () use ($students, $writer, $existing, &$report, &$seen, $options) {
            foreach ($students as $student) {
                $nim = $student['nim'];

                if (! preg_match(HotspotVoucherWriter::NIM_PATTERN, $nim)) {
                    $report['skipped']++;
                    $this->sample($report['invalid_samples'], $nim);
                    continue;
                }

                $seen[$nim] = true;

                $password = VoucherPassword::forStudent($nim, $student['birth_date']);

                if ($password->usesBirthDate()) {
                    $report['by_birth_date']++;
                } else {
                    $report['by_nim']++;
                    $this->sample($report['nim_samples'], $nim);
                }

                if ($report['dry_run']) {
                    isset($existing[$nim]) ? $report['updated']++ : $report['created']++;
                    continue;
                }

                $voucher = $writer->upsert($nim, [
                    'student_name' => $student['student_name'],
                    'program' => $student['program'],
                    'password' => $password->value,
                    'comment' => $options['comment'] ?? null,
                ]);

                $voucher->wasRecentlyCreated ? $report['created']++ : $report['updated']++;
            }
        };

        $report['dry_run'] ? $write() : DB::transaction($write);

        $report['revived'] = $report['dry_run']
            ? $this->revivableCount(array_keys($seen))
            : $writer->revived;

        // Penonaktifan sengaja di luar transaksi di atas: ia menulis ke database
        // RADIUS lewat jaringan, dan transaksi CIMS tidak boleh dibiarkan terbuka
        // memegang lock selama itu.
        $this->deactivateMissing($seen, $options, $report);

        return $report;
    }

    /**
     * Tutup akses voucher `pmb` yang NIM-nya tidak ada lagi di respons PMB.
     *
     * Status CIMS ditulis lebih dulu, RADIUS menyusul. Urutan itu yang benar
     * karena CIMS-lah sumber kebenaran: kalau RADIUS sedang mati, keputusannya
     * tetap tercatat, `last_error` memuat sebabnya, dan `radius:reconcile`
     * melaporkannya sebagai "diblokir di CIMS tapi RADIUS masih menerima login" —
     * satu-satunya selisih yang tidak kelihatan di tampilan mana pun.
     *
     * @param  array<string,true>  $seen
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $report
     */
    protected function deactivateMissing(array $seen, array $options, array &$report): void
    {
        if (! ($options['deactivate'] ?? true)) {
            $report['deactivate_skipped'] = 'Penonaktifan dilewati atas permintaan.';

            return;
        }

        if (($scope = $this->scope($options)) !== null) {
            $report['deactivate_skipped'] = "Tarikan ini disaring ({$scope}), jadi voucher yang tidak "
                .'muncul di respons belum tentu sudah tidak aktif. Penonaktifan otomatis hanya '
                .'berjalan pada tarikan penuh.';

            return;
        }

        if ($seen === []) {
            $report['deactivate_skipped'] = 'PMB tidak mengirim satu pun NIM yang bisa jadi username, '
                .'dan respons kosong tidak pernah diartikan sebagai "semua mahasiswa keluar".';

            return;
        }

        $ids = [];

        // Dibandingkan di PHP, bukan lewat `whereNotIn` berisi ribuan NIM: daftar
        // sepanjang itu menabrak batas jumlah parameter satu query.
        HotspotVoucher::query()
            ->where('source', HotspotVoucher::SOURCE_PMB)
            ->where('status', '!=', HotspotVoucher::STATUS_DISABLED)
            ->select(['id', 'nim'])
            ->chunkById(self::CHUNK, function ($rows) use ($seen, &$ids, &$report) {
                foreach ($rows as $row) {
                    if (isset($seen[(string) $row->nim])) {
                        continue;
                    }

                    $ids[] = (int) $row->id;
                    $this->sample($report['deactivate_samples'], (string) $row->nim);
                }
            });

        $report['deactivate_candidates'] = count($ids);

        if ($ids === []) {
            return;
        }

        if (! ($options['force'] ?? false) && ($truncated = $this->truncated(count($seen))) !== null) {
            $report['deactivate_skipped'] = $truncated;

            return;
        }

        if ($report['dry_run']) {
            return;
        }

        foreach (array_chunk($ids, self::CHUNK) as $batch) {
            HotspotVoucher::whereIn('id', $batch)->update([
                'status' => HotspotVoucher::STATUS_DISABLED,
                'disabled_reason' => self::DEACTIVATE_REASON,
                'last_error' => null,
            ]);

            // Barisnya dibaca ulang setelah statusnya tersimpan: toRadiusRows()
            // baru memuat 'Auth-Type := Reject' ketika status voucher disabled.
            $result = $this->applier->apply(HotspotVoucher::whereIn('id', $batch)->get());

            $report['deactivate_failed'] += $result['failed'];
            $report['deactivate_error'] ??= $result['error'];
        }

        $report['deactivated'] = count($ids);
    }

    /**
     * Nama saringan yang membuat tarikan ini hanya sebagian, atau null bila penuh.
     *
     * @param  array<string,mixed>  $options
     */
    protected function scope(array $options): ?string
    {
        $scope = [];

        if (filled($options['prodi'] ?? null)) {
            $scope[] = 'prodi';
        }

        if (filled($options['search'] ?? null)) {
            $scope[] = 'pencarian';
        }

        if ((int) ($options['limit'] ?? 0) > 0) {
            $scope[] = 'batas jumlah';
        }

        return $scope === [] ? null : implode(' + ', $scope);
    }

    /**
     * Alasan penonaktifan dibatalkan karena respons PMB terlihat terpotong, atau
     * null bila jumlahnya masih wajar.
     */
    protected function truncated(int $seen): ?string
    {
        $owned = HotspotVoucher::where('source', HotspotVoucher::SOURCE_PMB)->count();

        if ($owned === 0 || $seen >= (int) ceil($owned * self::DEACTIVATE_MIN_RATIO)) {
            return null;
        }

        return sprintf(
            'PMB hanya mengirim %s NIM untuk %s voucher PMB yang sudah ada (%d%%, ambang %d%%). '
            .'Pola itu lebih mirip respons yang terpotong di tengah paging daripada %s mahasiswa '
            .'yang benar-benar keluar, jadi seluruh penonaktifan dibatalkan. Jalankan ulang dengan '
            .'--force bila penurunan sebesar itu memang benar.',
            number_format($seen, 0, ',', '.'),
            number_format($owned, 0, ',', '.'),
            (int) round($seen / $owned * 100),
            (int) round(self::DEACTIVATE_MIN_RATIO * 100),
            number_format($owned - $seen, 0, ',', '.'),
        );
    }

    /**
     * NIM yang sudah punya voucher. Tidak lagi disaring per router: satu NIM kini
     * satu baris untuk seluruh kampus, karena yang menjawab Access-Request untuk
     * semua router hotspot hanya satu server RADIUS.
     *
     * @param  array<int,string>  $nims
     * @return array<string,true>
     */
    protected function existingNims(array $nims): array
    {
        $found = [];

        // Dipecah agar tidak menabrak batas jumlah parameter satu query.
        foreach (array_chunk($nims, self::CHUNK) as $chunk) {
            HotspotVoucher::whereIn('nim', $chunk)
                ->pluck('nim')
                ->each(function ($nim) use (&$found) {
                    $found[$nim] = true;
                });
        }

        return $found;
    }

    /**
     * Voucher yang akan hidup kembali karena NIM-nya muncul lagi di PMB.
     *
     * Hanya dipakai dry run. Pada tarikan sungguhan HotspotVoucherWriter yang
     * menghitungnya sambil menulis, dan itu yang benar-benar melihat transisinya;
     * di sini kondisinya harus ditiru dalam SQL. Yang menandai baris "dimatikan
     * otomatis" adalah disabled_reason yang terisi — blokir manual lewat tombol
     * selalu mengosongkannya, dan keputusan operator memang tidak boleh dibatalkan
     * oleh sinkronisasi.
     *
     * @param  array<int,string>  $nims
     */
    protected function revivableCount(array $nims): int
    {
        $total = 0;

        foreach (array_chunk($nims, self::CHUNK) as $chunk) {
            $total += HotspotVoucher::whereIn('nim', $chunk)
                ->where('source', HotspotVoucher::SOURCE_PMB)
                ->where('status', HotspotVoucher::STATUS_DISABLED)
                ->whereNotNull('disabled_reason')
                ->where('disabled_reason', '!=', '')
                ->count();
        }

        return $total;
    }

    /**
     * @param  array<int,string>  $bucket
     */
    protected function sample(array &$bucket, string $nim): void
    {
        if (count($bucket) < self::SAMPLES) {
            $bucket[] = $nim;
        }
    }
}
