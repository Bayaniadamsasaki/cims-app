<?php

namespace App\Console\Commands;

use App\Services\MonitoringService;
use Illuminate\Console\Command;

/**
 * Pintu masuk pemindaian terjadwal.
 *
 * Secara default perintah ini hanya MENJADWALKAN pekerjaan — satu job per
 * perangkat — lalu langsung selesai, sehingga scheduler per menit tidak pernah
 * tertahan menunggu perangkat yang lambat atau mati. Pemindaian berurutan di
 * dalam proses ini masih tersedia lewat `--sync` untuk keperluan terminal.
 */
class ScanDevicesCommand extends Command
{
    protected $signature = 'monitor:scan
                            {--sync : Pindai langsung di proses ini (menunggu setiap perangkat), tanpa antrean}';

    protected $description = 'Jadwalkan pemindaian status & metrik seluruh perangkat jaringan CIMS lewat antrean';

    public function handle(MonitoringService $monitoringService): int
    {
        if ($this->option('sync')) {
            $this->info('Memindai seluruh perangkat langsung di proses ini (tanpa antrean)...');

            $scanned = $monitoringService->scanAll();

            $this->info("Selesai memindai {$scanned} perangkat.");

            return self::SUCCESS;
        }

        $queue = config('monitoring.queue');
        $dispatched = $monitoringService->dispatchScans();

        $this->info("Pemindaian dijadwalkan untuk {$dispatched} perangkat pada antrean '{$queue}'.");
        $this->line('Perangkat yang pemindaiannya masih berjalan tidak dijadwalkan ulang.');

        if ($dispatched > 0) {
            $this->comment("Pastikan ada worker aktif: php artisan queue:work --queue={$queue}");
        }

        return self::SUCCESS;
    }
}
