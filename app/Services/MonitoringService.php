<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceMetric;
use App\Models\MonitoringLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class MonitoringService
{
    protected $alertService;

    public function __construct(AlertService $alertService)
    {
        $this->alertService = $alertService;
    }

    /**
     * Scan a single device and save/log its metrics.
     */
    public function scanDevice(Device $device): DeviceMetric
    {
        $now = Carbon::now();
        $ip = $device->ip_address;
        
        // 1. Determine if the device is online (ICMP Ping or Sim)
        $isOnline = false;
        $latency = null;
        $packetLoss = 0;
        
        // We will support simulated live nodes for standard RFC1918 dev addresses (like 10.x.x.x)
        // so the demo/development interface is dynamic and interactive.
        $isSimulated = empty($ip) || str_starts_with($ip, '10.');

        if ($isSimulated) {
            // Generate simulated metrics
            $isOnline = $device->status !== 'offline'; // Keep offline if status set to offline manually
            $latency = $isOnline ? rand(5, 45) : null;
            $packetLoss = $isOnline ? (rand(1, 100) > 98 ? rand(5, 20) : 0) : 100;
        } else {
            // Real ICMP ping check
            $pingResult = $this->pingHost($ip);
            $isOnline = $pingResult['online'];
            $latency = $pingResult['latency'];
            $packetLoss = $pingResult['packet_loss'];
        }

        // 2. Fetch or simulate SNMP Metrics (CPU, RAM, Temp, etc.)
        $cpu = null;
        $ram = null;
        $storage = null;
        $temp = null;
        $uptime = null;
        $rx = null;
        $tx = null;
        $interfaces = [];

        if ($isOnline) {
            if ($isSimulated) {
                // Generate realistic hardware metrics
                $cpu = rand(10, 85);
                $ram = rand(25, 90);
                $storage = rand(40, 75);
                $temp = rand(38, 72);
                $uptime = $device->metrics ? $device->metrics->last_uptime_seconds + 60 : rand(3600, 86400 * 5);
                
                // Bandwidth simulation (rx: 1Mbps-80Mbps, tx: 500Kbps-40Mbps)
                $rx = rand(1000000, 80000000);
                $tx = rand(500000, 40000000);

                $interfaces = [
                    ['name' => 'ether1-wan', 'status' => 'up', 'speed' => '1Gbps'],
                    ['name' => 'ether2-lan', 'status' => 'up', 'speed' => '1Gbps'],
                    ['name' => 'wlan1-2.4g', 'status' => 'up', 'speed' => '300Mbps'],
                    ['name' => 'wlan2-5g', 'status' => 'up', 'speed' => '867Mbps'],
                ];
            } else {
                // Real SNMP check (would call php-snmp if available)
                // Fallback to basic metrics if SNMP fails or not installed
                $snmpMetrics = $this->querySnmp($ip);
                $cpu = $snmpMetrics['cpu'];
                $ram = $snmpMetrics['ram'];
                $storage = $snmpMetrics['storage'];
                $temp = $snmpMetrics['temp'];
                $uptime = $snmpMetrics['uptime'];
                $rx = $snmpMetrics['rx'];
                $tx = $snmpMetrics['tx'];
                $interfaces = $snmpMetrics['interfaces'];
            }
        } else {
            $packetLoss = 100;
        }

        // 3. Update device status on device table if changed
        $newStatus = $isOnline ? 'active' : 'offline';
        if ($device->status === 'maintenance') {
            // Keep status as maintenance if set manually, but metrics show it's online
            $newStatus = 'maintenance';
        }

        if ($device->status !== $newStatus) {
            $device->update(['status' => $newStatus]);
            if ($newStatus === 'offline') {
                $this->alertService->dispatchAlert($device->name, 'CRITICAL_OFFLINE', "Device went OFFLINE / unreachable.");
            }
        }

        // Trigger resource usage alerts if thresholds exceeded
        if ($isOnline) {
            if ($cpu !== null && $cpu > 80) {
                $this->alertService->dispatchAlert($device->name, 'WARNING_HIGH_CPU', "High CPU utilization detected: {$cpu}%.");
            }
            if ($ram !== null && $ram > 85) {
                $this->alertService->dispatchAlert($device->name, 'WARNING_HIGH_RAM', "High Memory utilization detected: {$ram}%.");
            }
        }

        // 4. Save/Update Device Metrics (Current state)
        $metrics = DeviceMetric::updateOrCreate(
            ['device_id' => $device->id],
            [
                'last_ping_status' => $isOnline ? 'online' : 'offline',
                'last_ping_latency_ms' => $latency,
                'last_packet_loss_percent' => $packetLoss,
                'last_cpu_usage_percent' => $cpu,
                'last_ram_usage_percent' => $ram,
                'last_storage_usage_percent' => $storage,
                'last_temperature_celsius' => $temp,
                'last_uptime_seconds' => $uptime,
                'last_interface_status' => $interfaces,
                'last_bandwidth_rx_bps' => $rx,
                'last_bandwidth_tx_bps' => $tx,
                'last_checked_at' => $now,
            ]
        );

        // 5. Append Historical Log (Monitoring History)
        MonitoringLog::create([
            'device_id' => $device->id,
            'status' => $isOnline ? 'online' : 'offline',
            'ping_latency_ms' => $latency,
            'packet_loss_percent' => $packetLoss,
            'cpu_usage_percent' => $cpu,
            'ram_usage_percent' => $ram,
            'storage_usage_percent' => $storage,
            'temperature_celsius' => $temp,
            'uptime_seconds' => $uptime,
            'bandwidth_rx_bps' => $rx,
            'bandwidth_tx_bps' => $tx,
            'checked_at' => $now,
        ]);

        return $metrics;
    }

    /**
     * Run all devices scan.
     */
    public function scanAll(): int
    {
        $devices = Device::all();
        $count = 0;
        foreach ($devices as $device) {
            try {
                $this->scanDevice($device);
                $count++;
            } catch (\Exception $e) {
                Log::error("Failed scanning device ID {$device->id}: " . $e->getMessage());
            }
        }
        return $count;
    }

    /**
     * Check host status using ping (supports Windows & Linux).
     */
    protected function pingHost(string $ip): array
    {
        $latency = null;
        $online = false;
        
        $str = PHP_OS_FAMILY === 'Windows' 
            ? "ping -n 1 -w 1000 " . escapeshellarg($ip)
            : "ping -c 1 -W 1 " . escapeshellarg($ip);

        exec($str, $outcome, $status);

        if ($status === 0) {
            $online = true;
            // Parse latency from stdout
            foreach ($outcome as $line) {
                if (preg_match('/(?:time|waktu)[=<]([\d\.]+)\s*ms/i', $line, $matches)) {
                    $latency = (int) round($matches[1]);
                    break;
                }
            }
            if (is_null($latency)) {
                $latency = 1; // Default fallback to 1ms
            }
        }

        return [
            'online' => $online,
            'latency' => $latency,
            'packet_loss' => $online ? 0 : 100
        ];
    }

    /**
     * Query SNMP values from device if supported and configured.
     */
    protected function querySnmp(string $ip): array
    {
        // Default placeholders if SNMP fails or php-snmp is missing
        $data = [
            'cpu' => null,
            'ram' => null,
            'storage' => null,
            'temp' => null,
            'uptime' => null,
            'rx' => null,
            'tx' => null,
            'interfaces' => []
        ];

        if (!extension_loaded('snmp')) {
            return $data;
        }

        // Standard OIDs:
        // System Uptime: .1.3.6.1.2.1.1.3.0
        // CPU Load (Host Resources): .1.3.6.1.4.1.9.9.109.1.1.1.1.3 (Cisco) or .1.3.6.1.4.1.14988.1.1.3.15.0 (Mikrotik)
        try {
            snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
            
            // Try community public first
            $uptime = @snmpget($ip, 'public', '.1.3.6.1.2.1.1.3.0', 1000000);
            if ($uptime !== false) {
                $data['uptime'] = (int) ($uptime / 100); // convert timeticks to seconds
                
                // Get CPU load (MikroTik OID example)
                $cpu = @snmpget($ip, 'public', '.1.3.6.1.4.1.14988.1.1.3.15.0', 500000);
                if ($cpu !== false) {
                    $data['cpu'] = (int) $cpu;
                }
            }
        } catch (\Exception $e) {
            // Ignore SNMP read exceptions
        }

        return $data;
    }
}
