<?php

namespace App\Services;

use App\Models\HotspotVoucher;
use App\Support\HotspotVoucherWriter;
use App\Support\VoucherPassword;
use Illuminate\Support\Facades\DB;

/**
 * Ubah daftar mahasiswa SISKA menjadi baris voucher hotspot berstatus pending.
 *
 * Dipakai bersama oleh perintah `hotspot:sync-pmb` dan tombol "Tarik dari
 * SISKA" di halaman voucher, supaya keduanya menghasilkan data yang sama.
 *
 * Aturan password mengikuti janji ke mahasiswa: tanggal lahir dengan urutan
 * tanggal-bulan-tahun. Mahasiswa yang tanggal lahirnya belum terisi di SISKA
 * tetap dibuatkan voucher dengan password NIM — jumlah dan contoh NIM-nya
 * dilaporkan, karena mereka yang perlu diberi tahu passwordnya berbeda.
 */
class PmbVoucherSync
{
    /** Contoh NIM yang ditampilkan per kategori dalam laporan. */
    protected const SAMPLES = 10;

    public function __construct(protected PmbStudentService $pmb)
    {
    }

    /**
     * @param  array{prodi?:string|int|null,search?:string|null,batch_label?:string|null,profile?:string|null,server?:string|null,valid_until?:string|null,comment?:string|null,user_id?:int|null,limit?:int|null,dry_run?:bool,on_page?:callable|null}  $options
     * @return array{router_host:string,total:int,created:int,updated:int,skipped:int,by_birth_date:int,by_nim:int,nim_samples:array<int,string>,invalid_samples:array<int,string>,dry_run:bool}
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
            'skipped' => 0,
            'by_birth_date' => 0,
            'by_nim' => 0,
            'nim_samples' => [],
            'invalid_samples' => [],
            'dry_run' => (bool) ($options['dry_run'] ?? false),
        ];

        $writer = new HotspotVoucherWriter(
            routerHost: $routerHost,
            defaultProfile: $options['profile'] ?? (config('services.hotspot.default_profile') ?: null),
            defaultServer: $options['server'] ?? null,
            batchLabel: $options['batch_label'] ?? null,
            userId: $options['user_id'] ?? null,
            validUntil: $options['valid_until'] ?? null,
        );

        // Dry run tetap harus bisa membedakan baris baru dari baris lama, jadi
        // NIM yang sudah ada dibaca lebih dulu ketimbang ditulis lalu dibatalkan.
        $existing = $report['dry_run']
            ? $this->existingNims($routerHost, array_column($students, 'nim'))
            : [];

        $write = function () use ($students, $writer, $existing, &$report, $options) {
            foreach ($students as $student) {
                $nim = $student['nim'];

                if (! preg_match(HotspotVoucherWriter::NIM_PATTERN, $nim)) {
                    $report['skipped']++;
                    $this->sample($report['invalid_samples'], $nim);
                    continue;
                }

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

        return $report;
    }

    /**
     * NIM yang sudah punya voucher di router ini.
     *
     * @param  array<int,string>  $nims
     * @return array<string,true>
     */
    protected function existingNims(string $routerHost, array $nims): array
    {
        $found = [];

        // Dipecah agar tidak menabrak batas jumlah parameter satu query.
        foreach (array_chunk($nims, 500) as $chunk) {
            HotspotVoucher::where('router_host', $routerHost)
                ->whereIn('nim', $chunk)
                ->pluck('nim')
                ->each(function ($nim) use (&$found) {
                    $found[$nim] = true;
                });
        }

        return $found;
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
