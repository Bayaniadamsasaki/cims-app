<?php

namespace App\Console\Commands;

use App\Services\MonitoringService;
use Illuminate\Console\Command;

class ScanDevicesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:scan';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan all registered CIMS network devices and log status and metrics';

    /**
     * Execute the console command.
     */
    public function handle(MonitoringService $monitoringService)
    {
        $this->info('Starting campus device infrastructure health check...');
        $count = $monitoringService->scanAll();
        $this->info("Completed scan for {$count} device nodes successfully.");
    }
}
