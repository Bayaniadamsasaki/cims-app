<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\MaintenanceTicket;
use App\Models\MonitoringLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * Dashboard utama CIMS (tema terang) — struktur widget mengikuti
 * Docs/design_cims_dashboard.md: 3 metric card, chart trafik, dan dua daftar.
 *
 * Semua angka diambil dari database. Bila inventaris masih kosong, tiap widget
 * menampilkan empty state; tambahkan `?demo=1` untuk melihat tampilan dengan
 * data contoh (dirender di sisi klien, tidak menyentuh database).
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
            'demo' => $request->boolean('demo'),
        ]);
    }

    /** Angka untuk tiga metric card di baris atas. */
    private function metrics(array $alerts): array
    {
        $statusCounts = Device::query()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'totalDevices' => (int) $statusCounts->sum(),
            'onlineDevices' => (int) ($statusCounts['active'] ?? 0),
            'offlineDevices' => (int) ($statusCounts['offline'] ?? 0),
            'newDevices' => Device::where('created_at', '>=', now()->subDays(7))->count(),
            'activeAlerts' => count($alerts),
            'criticalAlerts' => count(array_filter($alerts, fn($a) => $a['severity'] === 'offline')),
            'maintenanceScheduled' => MaintenanceTicket::whereIn('status', ['open', 'in_progress'])->count(),
            'maintenanceToday' => MaintenanceTicket::whereDate('scheduled_at', today())->count(),
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

    /** Enam perangkat terakhir beserta status dan uptime dari log monitoring terbaru. */
    private function devices(): array
    {
        return Device::query()
            ->with(['building:id,name', 'floor:id,name', 'room:id,name'])
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
                    'status' => $this->statusKey($device->status),
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

    /** Status database ('active') dipetakan ke kunci badge di theme.jsx ('online'). */
    private function statusKey(?string $status): string
    {
        return match ($status) {
            'active' => 'online',
            'offline' => 'offline',
            'maintenance' => 'maintenance',
            default => 'warning',
        };
    }

    private function humanUptime(int $seconds): string
    {
        $days = intdiv($seconds, 86400);

        return $days >= 1 ? "{$days} hari" : intdiv($seconds, 3600) . ' jam';
    }

    /**
     * Alert diturunkan dari data yang sudah ada di database (perangkat offline,
     * anomali log monitoring terakhir, dan tiket maintenance terjadwal) supaya
     * dashboard tidak perlu menjalankan pemindaian jaringan saat dibuka.
     */
    private function alerts(): array
    {
        $alerts = collect()
            ->merge($this->offlineAlerts())
            ->merge($this->anomalyAlerts())
            ->merge($this->maintenanceAlerts());

        return $alerts
            ->sortByDesc('sort')
            ->take(5)
            ->map(fn($alert) => collect($alert)->except('sort')->all())
            ->values()
            ->all();
    }

    private function offlineAlerts(): array
    {
        return Device::where('status', 'offline')
            ->with(['monitoringLogs' => fn($q) => $q->latest('checked_at')->limit(1)])
            ->latest('updated_at')
            ->take(5)
            ->get()
            ->map(function (Device $device) {
                $at = $device->monitoringLogs->first()?->checked_at ?? $device->updated_at;

                return $this->alertRow("offline-{$device->id}", 'offline', 'Critical', 'Perangkat tidak merespons ICMP', $device->name, $at);
            })
            ->values()
            ->all();
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
