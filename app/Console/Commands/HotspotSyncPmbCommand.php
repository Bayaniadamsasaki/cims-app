<?php

namespace App\Console\Commands;

use App\Services\PmbVoucherSync;
use Illuminate\Console\Command;

/**
 * Tarik daftar mahasiswa dari API SISKA/PMB menjadi voucher hotspot pending.
 *
 * Password mengikuti tanggal lahir mahasiswa (tanggal-bulan-tahun). Tanggal
 * lahir yang belum terisi di SISKA tidak membuat barisnya dilewati: passwordnya
 * memakai NIM, dan jumlahnya dilaporkan terpisah supaya jelas siapa yang perlu
 * diberi tahu passwordnya berbeda.
 */
class HotspotSyncPmbCommand extends Command
{
    protected $signature = 'hotspot:sync-pmb
                            {--prodi= : Kode program studi, bila hanya satu prodi yang ditarik}
                            {--search= : Cari NIM atau nama tertentu di SISKA}
                            {--batch= : Label batch untuk baris voucher yang ditulis}
                            {--profile= : User profile RouterOS (bawaan dari .env)}
                            {--server= : Hotspot server RouterOS}
                            {--valid-until= : Tanggal berlaku voucher (Y-m-d)}
                            {--host= : Router tujuan (bawaan HOTSPOT_ROUTER_HOST di .env)}
                            {--limit= : Ambil sebagian saja, untuk uji coba}
                            {--dry-run : Tampilkan hitungannya tanpa menulis ke database}';

    protected $description = 'Tarik mahasiswa dari API SISKA/PMB menjadi voucher hotspot pending';

    public function handle(PmbVoucherSync $sync): int
    {
        $host = trim((string) ($this->option('host')
            ?: config('services.hotspot.router_host')
            ?: config('services.mikrotik.host')));

        if ($host === '') {
            $this->error('Router tujuan tidak diketahui — isi HOTSPOT_ROUTER_HOST di .env atau sebutkan --host=.');

            return self::FAILURE;
        }

        $this->info("Menarik daftar mahasiswa dari SISKA untuk router {$host}...");

        try {
            $report = $sync->run($host, [
                'prodi' => $this->option('prodi'),
                'search' => $this->option('search'),
                'batch_label' => $this->option('batch'),
                'profile' => $this->option('profile'),
                'server' => $this->option('server'),
                'valid_until' => $this->option('valid-until'),
                'limit' => (int) $this->option('limit'),
                'dry_run' => (bool) $this->option('dry-run'),
                'on_page' => fn (int $page, int $rows) => $this->line("  halaman {$page}: {$rows} mahasiswa"),
            ]);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($report['total'] === 0) {
            $this->warn('SISKA tidak mengembalikan satu pun mahasiswa untuk filter ini.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Hasil', 'Jumlah'], [
            ['Mahasiswa dari SISKA', $report['total']],
            ['Voucher baru', $report['created']],
            ['Voucher diperbarui', $report['updated']],
            ['Password dari tanggal lahir', $report['by_birth_date']],
            ['Password memakai NIM (tanggal lahir kosong)', $report['by_nim']],
            ['Dilewati (NIM tidak dipakai RouterOS)', $report['skipped']],
        ]);

        if ($report['nim_samples'] !== []) {
            $this->warn('Passwordnya NIM, contoh: ' . implode(', ', $report['nim_samples'])
                . ($report['by_nim'] > count($report['nim_samples']) ? ', ...' : ''));
            $this->line('Lengkapi tanggal lahirnya di SISKA lalu jalankan ulang perintah ini bila ingin '
                . 'passwordnya ikut berubah.');
        }

        if ($report['invalid_samples'] !== []) {
            $this->warn('NIM yang tidak bisa jadi username hotspot: ' . implode(', ', $report['invalid_samples']));
        }

        if ($report['dry_run']) {
            $this->info('Dry run: database tidak disentuh.');

            return self::SUCCESS;
        }

        $this->info('Semua baris berstatus pending — jalankan "Push ke Router" di halaman Voucher WiFi '
            . 'untuk mengaktifkannya.');

        return self::SUCCESS;
    }
}
