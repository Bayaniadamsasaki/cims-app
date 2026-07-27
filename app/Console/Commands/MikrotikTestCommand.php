<?php

namespace App\Console\Commands;

use App\Services\MikrotikService;
use Illuminate\Console\Command;

class MikrotikTestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mikrotik:test {host? : Override the router host/IP from config}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test connection to the MikroTik router and show system info';

    /**
     * Execute the console command.
     */
    public function handle(MikrotikService $mikrotik): int
    {
        $host = $this->argument('host') ?? config('services.mikrotik.host');

        $this->info("Connecting to MikroTik at {$host}:" . config('services.mikrotik.port')
            . ' (SSL: ' . (config('services.mikrotik.ssl') ? 'yes' : 'no') . ')...');

        $result = $mikrotik->testConnection($this->argument('host'));

        if (! $result['success']) {
            $this->error("Connection FAILED: {$result['error']}");

            return self::FAILURE;
        }

        $this->info('Connection OK!');
        $this->table(['Key', 'Value'], [
            ['Identity', $result['identity']],
            ['Board', $result['board'] ?? '-'],
            ['RouterOS Version', $result['version'] ?? '-'],
        ]);

        // Show live metrics
        $this->newLine();
        $this->info('Fetching system metrics...');
        $metrics = $mikrotik->getSystemMetrics($this->argument('host'));

        $this->table(['Metric', 'Value'], [
            ['CPU Load', ($metrics['cpu'] ?? '-') . '%'],
            ['RAM Usage', ($metrics['ram'] ?? '-') . '%'],
            ['Storage Usage', ($metrics['storage'] ?? '-') . '%'],
            ['Temperature', $metrics['temp'] !== null ? $metrics['temp'] . ' °C' : '-'],
            ['Uptime', $metrics['uptime'] !== null ? gmdate('z\d H:i:s', $metrics['uptime']) : '-'],
            ['RX Bandwidth', $metrics['rx'] !== null ? round($metrics['rx'] / 1000000, 2) . ' Mbps' : '-'],
            ['TX Bandwidth', $metrics['tx'] !== null ? round($metrics['tx'] / 1000000, 2) . ' Mbps' : '-'],
        ]);

        if (! empty($metrics['interfaces'])) {
            $this->newLine();
            $this->info('Interfaces:');
            $this->table(
                ['Name', 'Type', 'Status', 'MAC'],
                array_map(fn ($i) => [$i['name'], $i['type'] ?? '-', $i['status'], $i['mac'] ?? '-'], $metrics['interfaces'])
            );
        }

        return self::SUCCESS;
    }
}
