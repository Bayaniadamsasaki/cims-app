<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceMetric;
use App\Models\MaintenanceTicket;
use App\Models\MonitoringLog;
use App\Services\MonitoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * Dashboard utama CIMS (tema terang) — struktur widget mengikuti
 * Docs/design_cims_dashboard.md: 3 metric card, chart trafik, dan dua daftar.
 *
 * Semua angka diambil dari database hasil monitoring nyata. Perangkat yang belum
 * pernah dipindai tidak dihitung online maupun offline; bila inventaris atau
 * riwayat monitoring masih kosong, tiap widget menampilkan empty state.
 */
class DashboardWebController extends Controller
{
    /** Lebar satu bucket chart trafik: 12 titik untuk rentang 24 jam. */
    private const BUCKET_MINUTES = 120;

    /** Ambang batas yang dianggap anomali pada log monitoring terakhir. */
    private const PACKET_LOSS_THRESHOLD = 2.0;
    private const CPU_THRESHOLD = 85.0;

    public function index(Request $request)
    {
        $alerts = $this->alerts();

        return Inertia::render('Dashboard', [
            'metrics' => $this->metrics($alerts),
            'traffic' => $this->traffic(),
            'devices' => $this->devices(),
            'alerts' => $alerts,
        ]);
    }

    /**
     * Angka untuk tiga metric card di baris atas.
     *
     * Jumlah online/offline dibaca dari hasil monitoring terakhir
     * (device_metrics.last_ping_status), bukan dari kolom status inventaris —
     * perangkat berstatus 'active' di inventaris belum tentu benar-benar hidup.
     */
    private function metrics(array $alerts): array
    {
        $totalDevices = Device::count();
        $monitored = $this->monitoringStatusCounts();

        return [
            'totalDevices' => $totalDevices,
            'onlineDevices' => $monitored[MonitoringService::STATUS_ONLINE],
            'degradedDevices' => $monitored[MonitoringService::STATUS_DEGRADED],
            'unreachableDevices' => $monitored[MonitoringService::STATUS_UNREACHABLE],
            'monitoringErrorDevices' => $monitored[MonitoringService::STATUS_ERROR],
            // Belum pernah dipindai → tidak ada data, bukan "online".
            'unknownDevices' => max(0, $totalDevices - array_sum($monitored)),
            'newDevices' => Device::where('created_at', '>=', now()->subDays(7))->count(),
            'activeAlerts' => count($alerts),
            'criticalAlerts' => count(array_filter($alerts, fn($a) => $a['severity'] === 'offline')),
            'maintenanceScheduled' => MaintenanceTicket::whereIn('status', ['open', 'in_progress'])->count(),
            'maintenanceToday' => MaintenanceTicket::whereDate('scheduled_at', today())->count(),
        ];
    }

    /**
     * Rekap status monitoring terakhir per perangkat.
     *
     * @return array<string, int>
     */
    private function monitoringStatusCounts(): array
    {
        $counts = DeviceMetric::query()
            ->whereHas('device')
            ->selectRaw('last_ping_status, COUNT(*) AS total')
            ->groupBy('last_ping_status')
            ->pluck('total', 'last_ping_status');

        return [
            MonitoringService::STATUS_ONLINE => (int) ($counts[MonitoringService::STATUS_ONLINE] ?? 0),
            MonitoringService::STATUS_DEGRADED => (int) ($counts[MonitoringService::STATUS_DEGRADED] ?? 0),
            MonitoringService::STATUS_UNREACHABLE => (int) ($counts[MonitoringService::STATUS_UNREACHABLE] ?? 0),
            MonitoringService::STATUS_ERROR => (int) ($counts[MonitoringService::STATUS_ERROR] ?? 0),
        ];
    }


    /**
     * Rata-rata throughput uplink 24 jam terakhir, dibagi ke bucket 2 jam.
     * Bucket tanpa log tetap dikirim dengan nilai 0 agar sumbu X tidak bolong.
     */
    private function traffic(): array
    {
        $since = $this->bucketStart(now()->subDay());

        $logs = MonitoringLog::query()
            ->where('checked_at', '>=', $since)
            ->whereNotNull('bandwidth_rx_bps')
            ->get(['checked_at', 'bandwidth_rx_bps', 'bandwidth_tx_bps']);

        if ($logs->isEmpty()) {
            return [];
        }

        $buckets = [];
        for ($at = $since->copy(); $at <= now(); $at->addMinutes(self::BUCKET_MINUTES)) {
            $buckets[$at->format('Y-m-d H:i')] = ['label' => $at->format('H:i'), 'inbound' => [], 'outbound' => []];
        }

        foreach ($logs as $log) {
            $key = $this->bucketStart($log->checked_at)->format('Y-m-d H:i');

            if (isset($buckets[$key])) {
                $buckets[$key]['inbound'][] = (float) $log->bandwidth_rx_bps;
                $buckets[$key]['outbound'][] = (float) $log->bandwidth_tx_bps;
            }
        }

        return array_values(array_map(fn($bucket) => [
            'label' => $bucket['label'],
            'inbound' => $this->toMbps($bucket['inbound']),
            'outbound' => $this->toMbps($bucket['outbound']),
        ], $buckets));
    }

    /** Awal blok 2 jam tempat waktu tersebut jatuh — dipakai loop maupun pengelompokan. */
    private function bucketStart(Carbon $at): Carbon
    {
        $minutes = (int) floor(($at->hour * 60 + $at->minute) / self::BUCKET_MINUTES) * self::BUCKET_MINUTES;

        return $at->copy()->startOfDay()->addMinutes($minutes);
    }

    /** bps → Mbps, dibulatkan satu desimal. */
    private function toMbps(array $samples): float
    {
        if ($samples === []) {
            return 0.0;
        }

        return round(array_sum($samples) / count($samples) / 1_000_000, 1);
    }

    /** Enam perangkat terakhir beserta status monitoring nyata dan uptime terukur. */
    private function devices(): array
    {
        return Device::query()
            ->with(['building:id,name', 'floor:id,name', 'room:id,name', 'metrics'])
            ->with(['monitoringLogs' => fn($q) => $q->latest('checked_at')->limit(1)])
            ->latest()
            ->take(6)
            ->get()
            ->map(function (Device $device) {
                $log = $device->monitoringLogs->first();

                return [
                    'id' => $device->id,
                    'name' => $device->name,
                    'ip' => $device->ip_address,
                    'location' => $this->locationOf($device),
                    'status' => $this->statusKey($device),
                    'uptime' => $log?->uptime_seconds ? $this->humanUptime((int) $log->uptime_seconds) : null,
                ];
            })
            ->all();
    }

    /** "Gedung · Lt. X · Ruang" — bagian yang kosong dilewati. */
    private function locationOf(Device $device): string
    {
        return collect([$device->building?->name, $device->floor?->name, $device->room?->name])
            ->filter()
            ->implode(' · ');
    }

    /**
     * Kunci badge status untuk theme.jsx.
     *
     * Perangkat dalam masa maintenance ditandai lebih dulu; sisanya memakai
     * hasil monitoring terakhir. Perangkat yang belum pernah dipindai menjadi
     * 'unknown' (No Data), tidak diklaim online.
     */
    private function statusKey(Device $device): string
    {
        if ($device->status === 'maintenance') {
            return 'maintenance';
        }

        return match ($device->metrics?->last_ping_status) {
            MonitoringService::STATUS_ONLINE => 'online',
            MonitoringService::STATUS_DEGRADED => 'degraded',
            MonitoringService::STATUS_UNREACHABLE => 'unreachable',
            MonitoringService::STATUS_ERROR => 'error',
            'offline' => 'offline',
            default => 'unknown',
        };
    }

    private function humanUptime(int $seconds): string
    {
        $days = intdiv($seconds, 86400);

        return $days >= 1 ? "{$days} hari" : intdiv($seconds, 3600) . ' jam';
    }

    /**
     * Alert diturunkan dari data monitoring yang sudah tersimpan (hasil pindai
     * terakhir, anomali log monitoring, dan tiket maintenance terjadwal) supaya
     * dashboard tidak perlu menjalankan pemindaian jaringan saat dibuka.
     */
    private function alerts(): array
    {
        $alerts = collect()
            ->merge($this->offlineAlerts())
            ->merge($this->degradedAlerts())
            ->merge($this->anomalyAlerts())
            ->merge($this->maintenanceAlerts());

        return $alerts
            ->sortByDesc('sort')
            ->take(5)
            ->map(fn($alert) => collect($alert)->except('sort')->all())
            ->values()
            ->all();
    }

    /** Perangkat yang pemindaian terakhirnya unreachable atau gagal dijalankan. */
    private function offlineAlerts(): array
    {
        return $this->devicesWithMonitoringStatus([
            MonitoringService::STATUS_UNREACHABLE,
            MonitoringService::STATUS_ERROR,
        ])->map(function (Device $device) {
            $message = $device->metrics?->last_ping_status === MonitoringService::STATUS_ERROR
                ? 'Monitoring gagal dijalankan — perangkat tidak dapat dicek'
                : 'Perangkat tidak merespons ICMP';

            return $this->alertRow(
                "offline-{$device->id}",
                'offline',
                'Critical',
                $message,
                $device->name,
                $device->metrics?->last_checked_at ?? $device->updated_at,
            );
        })->all();
    }

    /** Perangkat yang menjawab ICMP tetapi metriknya gagal dibaca. */
    private function degradedAlerts(): array
    {
        return $this->devicesWithMonitoringStatus([MonitoringService::STATUS_DEGRADED])
            ->map(fn(Device $device) => $this->alertRow(
                "degraded-{$device->id}",
                'warning',
                'Warning',
                'Perangkat menjawab ICMP tetapi metrik gagal dibaca',
                $device->name,
                $device->metrics?->last_checked_at ?? $device->updated_at,
            ))
            ->all();
    }

    /**
     * @param  list<string>  $statuses
     * @return \Illuminate\Support\Collection<int, Device>
     */
    private function devicesWithMonitoringStatus(array $statuses): \Illuminate\Support\Collection
    {
        return Device::query()
            ->with('metrics')
            ->whereHas('metrics', fn($q) => $q->whereIn('last_ping_status', $statuses))
            ->take(5)
            ->get()
            ->values();
    }

    /** Log monitoring 24 jam terakhir yang melewati ambang packet loss atau CPU. */
    private function anomalyAlerts(): array
    {
        return MonitoringLog::query()
            ->where('checked_at', '>=', now()->subDay())
            ->where(fn($q) => $q
                ->where('packet_loss_percent', '>', self::PACKET_LOSS_THRESHOLD)
                ->orWhere('cpu_usage_percent', '>', self::CPU_THRESHOLD))
            ->with('device:id,name')
            ->latest('checked_at')
            ->take(20)
            ->get()
            ->unique('device_id')
            ->take(5)
            ->map(function (MonitoringLog $log) {
                $message = (float) $log->cpu_usage_percent > self::CPU_THRESHOLD
                    ? 'Utilisasi CPU ' . round((float) $log->cpu_usage_percent) . '% melewati ambang batas'
                    : 'Packet loss ' . round((float) $log->packet_loss_percent, 1) . '% pada uplink';

                return $this->alertRow(
                    "log-{$log->id}",
                    'warning',
                    'Warning',
                    $message,
                    $log->device?->name ?? 'Perangkat tidak dikenal',
                    $log->checked_at,
                );
            })
            ->values()
            ->all();
    }

    private function maintenanceAlerts(): array
    {
        return MaintenanceTicket::whereIn('status', ['open', 'in_progress'])
            ->with('device:id,name')
            ->latest('scheduled_at')
            ->take(5)
            ->get()
            ->map(fn(MaintenanceTicket $ticket) => $this->alertRow(
                "ticket-{$ticket->id}",
                'maintenance',
                'Maintenance',
                $ticket->title,
                $ticket->device?->name ?? 'Tanpa perangkat',
                $ticket->scheduled_at ?? $ticket->created_at,
            ))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function alertRow(string $id, string $severity, string $label, string $message, string $device, ?Carbon $at): array
    {
        $at ??= now();

        return [
            'id' => $id,
            'severity' => $severity,
            'severityLabel' => $label,
            'message' => $message,
            'device' => $device,
            'at' => $at->toIso8601String(),
            'ago' => $at->locale('id')->diffForHumans(),
            'sort' => $at->getTimestamp(),
        ];
    }
}
