<?php

namespace App\Console\Commands;

use App\Support\VoucherDeduplicator;
use Illuminate\Console\Command;

/**
 * Pratinjau dan eksekusi penyatuan baris voucher berkembar-NIM.
 *
 * Migrasi ke kunci NIM tunggal memanggil deduplikator yang sama, jadi perintah ini
 * sebenarnya opsional — gunanya melihat apa yang akan hilang SEBELUM migrasi
 * berjalan. Penghapusannya tidak bisa dibatalkan migrate:rollback, jadi backup
 * database dulu.
 */
class HotspotVouchersDedupeCommand extends Command
{
    protected $signature = 'hotspot:vouchers-dedupe
                            {--dry-run : Hitung saja, jangan hapus apa pun}';

    protected $description = 'Satukan baris voucher dengan NIM sama (yang terbaru menang)';

    public function handle(VoucherDeduplicator $deduplicator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $report = $deduplicator->run(apply: ! $dryRun);

        $this->table(['Yang dihitung', 'Jumlah'], [
            ['Baris voucher sebelum', number_format($report['total'], 0, ',', '.')],
            ['NIM unik', number_format($report['unique'], 0, ',', '.')],
            ['NIM yang berkembar', number_format($report['duplicate_nims'], 0, ',', '.')],
            ['Baris yang dihapus', number_format($report['deleted'], 0, ',', '.')],
            ['Di antaranya password berbeda', number_format($report['password_conflicts'], 0, ',', '.')],
            ['Baris sesudah', number_format($report['total'] - $report['deleted'], 0, ',', '.')],
        ]);

        if ($report['samples'] !== []) {
            $this->line('Contoh (paling banyak '.count($report['samples']).' baris):');
            $this->table(['NIM', 'Router yang dipertahankan', 'Router yang dibuang', 'Kredensial'], $report['samples']);
        }

        if ($report['deleted'] === 0) {
            $this->info('Tidak ada NIM berkembar. Aman untuk migrate.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('Dry run: tidak ada yang dihapus. Backup database dulu, lalu jalankan tanpa --dry-run.');

            return self::SUCCESS;
        }

        $this->info($report['deleted'].' baris kembar dihapus; tiap NIM sekarang tinggal satu baris.');

        if ($report['password_conflicts'] > 0) {
            $this->warn($report['password_conflicts'].' NIM tadinya punya password berbeda antar router. '
                .'Yang dipakai sekarang adalah baris terbaru — password itulah yang akan masuk RADIUS.');
        }

        return self::SUCCESS;
    }
}
