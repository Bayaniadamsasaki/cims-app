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
 *
 * Perintah ini juga menutup akses NIM yang sudah tidak ada di SISKA. Aturan dan
 * pengamannya ada di PmbVoucherSync; yang perlu diingat di sini cuma dua opsi
 * yang mengaturnya: --no-deactivate mematikannya, dan --force menembus pengaman
 * yang membatalkan penonaktifan saat jumlah NIM dari SISKA turun drastis.
 */
class HotspotSyncPmbCommand extends Command
{
    protected $signature = 'hotspot:sync-pmb
                            {--prodi= : Kode program studi, bila hanya satu prodi yang ditarik}
                            {--search= : Cari NIM atau nama tertentu di SISKA}
                            {--batch= : Label batch untuk baris voucher yang ditulis}
                            {--profile= : Group RADIUS untuk paket (bawaan HOTSPOT_RADIUS_DEFAULT_GROUP)}
                            {--server= : Hotspot server RouterOS}
                            {--valid-until= : Tanggal berlaku voucher (Y-m-d)}
                            {--host= : Router pencatat, disimpan di kolom router_host (bawaan HOTSPOT_ROUTER_HOST)}
                            {--limit= : Ambil sebagian saja, untuk uji coba}
                            {--no-deactivate : Jangan tutup akses NIM yang sudah tidak ada di SISKA}
                            {--force : Tetap tutup akses walau jumlah NIM dari SISKA turun drastis}
                            {--dry-run : Tampilkan hitungannya tanpa menulis ke database}';

    protected $description = 'Tarik mahasiswa dari API SISKA/PMB menjadi voucher hotspot pending';

    public function handle(PmbVoucherSync $sync): int
    {
        $host = trim((string) ($this->option('host')
            ?: config('services.hotspot.router_host')
            ?: config('services.mikrotik.host')));

        if ($host === '') {
            $this->error('Router pencatat tidak diketahui — isi HOTSPOT_ROUTER_HOST di .env atau sebutkan --host=.');

            return self::FAILURE;
        }

        $this->info("Menarik daftar mahasiswa dari SISKA (router pencatat: {$host})...");

        try {
            $report = $sync->run($host, [
                'prodi' => $this->option('prodi'),
                'search' => $this->option('search'),
                'batch_label' => $this->option('batch'),
                'profile' => $this->option('profile'),
                'server' => $this->option('server'),
                'valid_until' => $this->option('valid-until'),
                'limit' => (int) $this->option('limit'),
                'deactivate' => ! $this->option('no-deactivate'),
                'force' => (bool) $this->option('force'),
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

        return $this->report($report);
    }

    /**
     * Cetak hasilnya, lalu tentukan exit code-nya.
     *
     * Penonaktifan yang sudah tercatat di CIMS tapi gagal ditulis ke RADIUS
     * dikembalikan sebagai FAILURE, bukan sekadar peringatan: mahasiswanya masih
     * bisa login padahal halaman voucher menyebutnya nonaktif, dan selisih itu
     * tidak kelihatan di tampilan mana pun tanpa radius:reconcile.
     *
     * @param  array<string,mixed>  $report
     */
    protected function report(array $report): int
    {
        $this->newLine();
        $this->table(['Hasil', 'Jumlah'], [
            ['Mahasiswa dari SISKA', $report['total']],
            ['Voucher baru', $report['created']],
            ['Voucher diperbarui', $report['updated']],
            ['Hidup kembali (muncul lagi di SISKA)', $report['revived']],
            ['Password dari tanggal lahir', $report['by_birth_date']],
            ['Password memakai NIM (tanggal lahir kosong)', $report['by_nim']],
            ['Dilewati (NIM tidak bisa jadi username)', $report['skipped']],
            ['Tidak ada lagi di SISKA', $report['deactivate_candidates']],
            ['Di antaranya ditutup sekarang', $report['deactivated']],
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

        if ($report['deactivate_samples'] !== []) {
            $this->newLine();
            $this->warn('Sudah tidak ada di SISKA, contoh: ' . implode(', ', $report['deactivate_samples'])
                . ($report['deactivate_candidates'] > count($report['deactivate_samples']) ? ', ...' : ''));
        }

        if ($report['deactivate_skipped'] !== null) {
            $this->warn($report['deactivate_skipped']);
        }

        if ($report['revived'] > 0) {
            $this->newLine();
            $this->warn("{$report['revived']} voucher hidup kembali dan berstatus pending. RADIUS masih "
                . 'menolak NIM itu sampai barisnya ditulis ulang — klik "Terapkan ke RADIUS" di halaman '
                . 'Voucher WiFi, atau jalankan `php artisan radius:reconcile --fix`.');
        }

        if ($report['dry_run']) {
            $this->newLine();
            $this->info('Dry run: database tidak disentuh.');

            return self::SUCCESS;
        }

        if ($report['deactivate_failed'] > 0) {
            $this->newLine();
            $this->error("{$report['deactivate_failed']} voucher sudah ditutup di CIMS tapi gagal ditulis ke "
                . 'RADIUS, jadi mahasiswanya MASIH BISA login: '
                . ($report['deactivate_error'] ?? 'tanpa keterangan'));
            $this->line('Jalankan `php artisan radius:reconcile` untuk melihat dan memperbaikinya.');

            return self::FAILURE;
        }

        $this->info('Voucher yang baru atau berubah berstatus pending — klik "Terapkan ke RADIUS" di '
            . 'halaman Voucher WiFi untuk mengaktifkannya.');

        return self::SUCCESS;
    }
}
