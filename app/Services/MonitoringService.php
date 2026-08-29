<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceMetric;
use App\Models\MonitoringLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Monitoring jaringan nyata, tanpa simulasi.
 *
 * Setiap angka yang tersimpan di sini berasal dari jaringan sungguhan: ICMP
 * untuk jangkauan, RouterOS API atau SNMP untuk metrik perangkat. Kalau sebuah
 * metrik tidak bisa diambil, statusnya ditandai apa adanya dan nilai valid
 * terakhir dibiarkan utuh — tidak ada nilai acak, tidak ada interface karangan,
 * dan alamat privat RFC1918 (10/8, 172.16/12, 192.168/16) diperlakukan sebagai
 * alamat jaringan biasa yang tetap dicek sungguhan.
 */
class MonitoringService
{
    /** Perangkat menjawab ICMP dan metriknya berhasil dibaca. */
    public const STATUS_ONLINE = 'online';

    /** Perangkat menjawab ICMP tetapi sumber metriknya (API/SNMP) gagal. */
    public const STATUS_DEGRADED = 'degraded';

    /** Tidak ada balasan ICMP dari alamat perangkat. */
    public const STATUS_UNREACHABLE = 'unreachable';

    /** Monitoring tidak bisa dijalankan: alamat tidak valid, ICMP mati, dsb. */
    public const STATUS_ERROR = 'error';

    /**
     * Kolom metrik pada device_metrics dan kunci hasil pembacaan protokolnya.
     */
    protected const METRIC_COLUMNS = [
        'last_cpu_usage_percent' => 'cpu',
        'last_ram_usage_percent' => 'ram',
        'last_storage_usage_percent' => 'storage',
        'last_temperature_celsius' => 'temp',
        'last_uptime_seconds' => 'uptime',
        'last_bandwidth_rx_bps' => 'rx',
        'last_bandwidth_tx_bps' => 'tx',
        'last_interface_status' => 'interfaces',
    ];

    public function __construct(
        protected AlertService $alertService,
        protected MikrotikService $mikrotikService,
        protected PingService $pingService,
    ) {
    }

    /**
     * Cek satu perangkat lewat jaringan sungguhan, lalu simpan metrik dan
     * riwayatnya. Urutannya: validasi target, tentukan protokol, cek nyata,
     * ambil metrik nyata, simpan metrik, simpan riwayat, picu alert.
     */
    public function scanDevice(Device $device): DeviceMetric
    {
        $now = Carbon::now();
        $address = trim((string) $device->ip_address);
        $host = $this->monitoringHost($address);

        // 1. Validasi alamat monitoring.
        if (! $this->isMonitorableAddress($address)) {
            return $this->recordFailure(
                $device,
                $now,
                self::STATUS_ERROR,
                $address === ''
                    ? 'Alamat monitoring belum diisi pada data perangkat.'
                    : "Alamat monitoring tidak valid: \"{$address}\".",
                'MONITORING_CONFIG_ERROR',
                null
            );
        }

        // 2. Cek jangkauan nyata lewat ICMP.
        $ping = $this->pingService->check($host);

        if ($ping['error'] !== null) {
            return $this->recordFailure(
                $device,
                $now,
                self::STATUS_ERROR,
                $ping['error'],
                'MONITORING_ERROR',
                $ping['packet_loss']
            );
        }

        if (! $ping['online']) {
            return $this->recordFailure(
                $device,
                $now,
                self::STATUS_UNREACHABLE,
                "Tidak ada balasan ICMP dari {$host} (timeout).",
                'CRITICAL_OFFLINE',
                $ping['packet_loss'] ?? 100
            );
        }

        // 3. Ambil metrik nyata sesuai protokol yang tersedia pada perangkat.
        $probe = $this->collectMetrics($device, $address);
        $succeeded = $probe['error'] === null;
        $status = $succeeded ? self::STATUS_ONLINE : self::STATUS_DEGRADED;

        $payload = [
            'last_ping_status' => $status,
            'last_ping_latency_ms' => $ping['latency'],
            'last_packet_loss_percent' => $ping['packet_loss'],
            'last_checked_at' => $now,
        ];

        if ($succeeded) {
            // Hanya pembacaan yang berhasil boleh menimpa kolom metrik. Saat
            // pembacaan gagal, nilai valid terakhir dibiarkan apa adanya.
            foreach (self::METRIC_COLUMNS as $column => $key) {
                $payload[$column] = $probe['metrics'][$key];
            }
        } else {
            Log::warning(
                "Monitoring metrik gagal untuk perangkat #{$device->id} ({$device->name}) di {$host}: {$probe['error']}"
            );
        }

        $previousStatus = $this->currentStatus($device);

        // 4. Simpan metrik terkini.
        $metric = DeviceMetric::updateOrCreate(['device_id' => $device->id], $payload);

        // 5. Riwayat mencatat apa yang benar-benar terukur pada siklus ini.
        $this->recordHistory(
            $device,
            $now,
            $status,
            $ping['latency'],
            $ping['packet_loss'],
            $succeeded ? $probe['metrics'] : null
        );

        $this->syncDeviceStatus($device, $status);

        // 6. Alert lewat arsitektur alert yang sudah ada.
        if (! $succeeded && $previousStatus !== self::STATUS_DEGRADED) {
            $this->alertService->dispatchAlert(
                $device->name,
                'MONITORING_ERROR',
                "Perangkat menjawab ICMP tetapi metrik gagal dibaca: {$probe['error']}"
            );
        }

        if ($succeeded) {
            $this->checkResourceThresholds($device, $probe['metrics']);
        }

        return $metric;
    }

    /**
     * Jalankan pengecekan untuk seluruh perangkat terdaftar.
     */
    public function scanAll(): int
    {
        $count = 0;

        foreach (Device::with(['vendor', 'operatingSystem', 'metrics'])->get() as $device) {
            try {
                $this->scanDevice($device);
                $count++;
            } catch (\Throwable $e) {
                Log::error("Gagal memindai perangkat #{$device->id}: " . $e->getMessage());
            }
        }

        return $count;
    }

    /**
     * Catat kegagalan monitoring apa adanya: status nyata tersimpan, riwayat
     * terisi, penyebabnya masuk log, dan metrik valid terakhir tidak disentuh
     * sama sekali — tidak ada data pengganti yang dikarang.
     */
    protected function recordFailure(
        Device $device,
        Carbon $now,
        string $status,
        string $reason,
        string $alertType,
        ?int $packetLoss
    ): DeviceMetric {
        Log::warning("Monitoring gagal untuk perangkat #{$device->id} ({$device->name}): {$reason}");

        $previousStatus = $this->currentStatus($device);

        $metric = DeviceMetric::updateOrCreate(
            ['device_id' => $device->id],
            [
                'last_ping_status' => $status,
                'last_ping_latency_ms' => null,
                'last_packet_loss_percent' => $packetLoss,
                'last_checked_at' => $now,
            ]
        );

        $this->recordHistory($device, $now, $status, null, $packetLoss, null);
        $this->syncDeviceStatus($device, $status);

        if ($previousStatus !== $status) {
            $this->alertService->dispatchAlert($device->name, $alertType, $reason);
        }

        return $metric;
    }

    /**
     * Simpan satu baris riwayat monitoring.
     *
     * @param  array<string,mixed>|null  $metrics  null bila metrik tidak terukur
     */
    protected function recordHistory(
        Device $device,
        Carbon $now,
        string $status,
        ?int $latency,
        ?int $packetLoss,
        ?array $metrics
    ): void {
        MonitoringLog::create([
            'device_id' => $device->id,
            'status' => $status,
            'ping_latency_ms' => $latency,
            'packet_loss_percent' => $packetLoss,
            'cpu_usage_percent' => $metrics['cpu'] ?? null,
            'ram_usage_percent' => $metrics['ram'] ?? null,
            'storage_usage_percent' => $metrics['storage'] ?? null,
            'temperature_celsius' => $metrics['temp'] ?? null,
            'uptime_seconds' => $metrics['uptime'] ?? null,
            'bandwidth_rx_bps' => $metrics['rx'] ?? null,
            'bandwidth_tx_bps' => $metrics['tx'] ?? null,
            'checked_at' => $now,
        ]);
    }

    /**
     * Status inventaris perangkat mengikuti hasil jangkauan nyata. Status
     * 'maintenance' diset manual sehingga tidak pernah ditimpa, dan status
     * error berarti jangkauannya tidak diketahui — jadi juga tidak menimpa
     * apa pun.
     */
    protected function syncDeviceStatus(Device $device, string $status): void
    {
        if ($device->status === 'maintenance' || $status === self::STATUS_ERROR) {
            return;
        }

        $target = $status === self::STATUS_UNREACHABLE ? 'offline' : 'active';

        if ($device->status !== $target) {
            $device->update(['status' => $target]);
        }
    }

    /**
     * Ambang batas hanya dievaluasi dari metrik yang baru saja terbaca, supaya
     * nilai lama yang dipertahankan tidak memicu alert berulang.
     *
     * @param  array<string,mixed>  $metrics
     */
    protected function checkResourceThresholds(Device $device, array $metrics): void
    {
        if (($metrics['cpu'] ?? null) !== null && $metrics['cpu'] > 80) {
            $this->alertService->dispatchAlert(
                $device->name,
                'WARNING_HIGH_CPU',
                "High CPU utilization detected: {$metrics['cpu']}%."
            );
        }

        if (($metrics['ram'] ?? null) !== null && $metrics['ram'] > 85) {
            $this->alertService->dispatchAlert(
                $device->name,
                'WARNING_HIGH_RAM',
                "High Memory utilization detected: {$metrics['ram']}%."
            );
        }
    }

    /**
     * Status monitoring hasil siklus sebelumnya, dipakai supaya alert hanya
     * dikirim saat statusnya benar-benar berubah.
     */
    protected function currentStatus(Device $device): ?string
    {
        return $device->metrics?->last_ping_status;
    }

    /**
     * Alamat yang bisa dimonitor. FILTER_FLAG_NO_PRIV_RANGE sengaja TIDAK
     * dipakai: 10/8, 172.16/12, dan 192.168/16 adalah alamat jaringan kampus
     * yang sah dan wajib dicek sungguhan seperti alamat publik.
     */
    protected function isMonitorableAddress(string $address): bool
    {
        $host = $this->monitoringHost($address);

        return $host !== '' && filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Bagian host dari alamat inventaris, membuang ":port" bila ada (mis.
     * "192.168.1.254:8729" untuk RouterOS API di port non-standar).
     */
    protected function monitoringHost(string $address): string
    {
        if (substr_count($address, ':') === 1) {
            [$host] = explode(':', $address);

            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                return $host;
            }
        }

        return $address;
    }

    /**
     * Tentukan protokol nyata perangkat lalu baca metriknya.
     *
     * @return array{metrics:array<string,mixed>,error:?string}
     */
    protected function collectMetrics(Device $device, string $address): array
    {
        $host = $this->monitoringHost($address);

        if (! $this->isMikrotikDevice($device)) {
            return $this->querySnmp($host);
        }

        $metrics = $this->normaliseMetrics($this->mikrotikService->getSystemMetrics($address));
        $error = $this->mikrotikService->lastErrorFor($address);

        if ($error === null && $this->hasNoMetricValues($metrics)) {
            $error = "tidak mengembalikan data resource dari {$host}.";
        }

        return [
            'metrics' => $metrics,
            'error' => $error === null ? null : "RouterOS API {$error}",
        ];
    }

    /**
     * Apakah perangkat ini MikroTik (RouterOS), dilihat dari master data vendor
     * atau sistem operasinya.
     */
    protected function isMikrotikDevice(Device $device): bool
    {
        $vendor = strtolower($device->vendor->name ?? '');
        $os = strtolower($device->operatingSystem->name ?? '');

        return str_contains($vendor, 'mikrotik') || str_contains($os, 'routeros');
    }

    /**
     * Baca metrik SNMP nyata. Kegagalan dikembalikan sebagai error yang bisa
     * ditindaklanjuti, bukan sebagai deretan nilai kosong tanpa penjelasan.
     *
     * @return array{metrics:array<string,mixed>,error:?string}
     */
    protected function querySnmp(string $ip): array
    {
        $data = $this->normaliseMetrics([]);

        if (! extension_loaded('snmp')) {
            return [
                'metrics' => $data,
                'error' => 'Ekstensi PHP snmp tidak terpasang, metrik SNMP tidak dapat dibaca.',
            ];
        }

        // OID standar:
        // System Uptime: .1.3.6.1.2.1.1.3.0
        // CPU Load MikroTik: .1.3.6.1.4.1.14988.1.1.3.15.0
        try {
            snmp_set_valueretrieval(SNMP_VALUE_PLAIN);

            $uptime = @snmpget($ip, 'public', '.1.3.6.1.2.1.1.3.0', 1000000);

            if ($uptime === false) {
                return [
                    'metrics' => $data,
                    'error' => "SNMP tidak menjawab dari {$ip} (timeout, agen SNMP mati, atau community tidak cocok).",
                ];
            }

            $data['uptime'] = (int) ($uptime / 100); // timeticks -> detik

            $cpu = @snmpget($ip, 'public', '.1.3.6.1.4.1.14988.1.1.3.15.0', 500000);

            if ($cpu !== false) {
                $data['cpu'] = (int) $cpu;
            }

            return ['metrics' => $data, 'error' => null];
        } catch (\Throwable $e) {
            return ['metrics' => $data, 'error' => "SNMP error dari {$ip}: {$e->getMessage()}"];
        }
    }

    /**
     * Samakan bentuk hasil pembacaan metrik dari protokol mana pun.
     *
     * @param  array<string,mixed>  $metrics
     * @return array<string,mixed>
     */
    protected function normaliseMetrics(array $metrics): array
    {
        return [
            'cpu' => $metrics['cpu'] ?? null,
            'ram' => $metrics['ram'] ?? null,
            'storage' => $metrics['storage'] ?? null,
            'temp' => $metrics['temp'] ?? null,
            'uptime' => $metrics['uptime'] ?? null,
            'rx' => $metrics['rx'] ?? null,
            'tx' => $metrics['tx'] ?? null,
            'interfaces' => $metrics['interfaces'] ?? [],
        ];
    }

    /**
     * Pembacaan yang tidak menghasilkan satu pun nilai — perangkat menjawab
     * ICMP tetapi sumber metriknya tidak memberi apa-apa.
     *
     * @param  array<string,mixed>  $metrics
     */
    protected function hasNoMetricValues(array $metrics): bool
    {
        foreach (['cpu', 'ram', 'storage', 'temp', 'uptime', 'rx', 'tx'] as $key) {
            if ($metrics[$key] !== null) {
                return false;
            }
        }

        return empty($metrics['interfaces']);
    }
}
