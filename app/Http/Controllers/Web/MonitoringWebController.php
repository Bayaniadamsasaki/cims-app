<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\MonitoringLog;
use App\Models\SpeedtestResult;
use App\Services\MonitoringService;
use App\Services\SpeedtestService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MonitoringWebController extends Controller
{
    protected $monitoringService;
    protected $speedtestService;

    public function __construct(MonitoringService $monitoringService, SpeedtestService $speedtestService)
    {
        $this->monitoringService = $monitoringService;
        $this->speedtestService = $speedtestService;
    }

    /**
     * Display the general monitoring health board.
     */
    public function index(Request $request): Response
    {
        // Load devices with their current metric snapshots
        $devices = Device::with(['metrics', 'building', 'category'])->get();

        $totalCount = $devices->count();

        // Ringkasan dihitung dari status monitoring nyata, bukan dari status
        // inventaris. Perangkat yang belum pernah dicek masuk "no data".
        $counts = [
            MonitoringService::STATUS_ONLINE => 0,
            MonitoringService::STATUS_DEGRADED => 0,
            MonitoringService::STATUS_UNREACHABLE => 0,
            MonitoringService::STATUS_ERROR => 0,
            'unknown' => 0,
        ];

        $alerts = [];

        foreach ($devices as $dev) {
            $m = $dev->metrics;
            $status = $m?->last_ping_status ?? 'unknown';
            $counts[array_key_exists($status, $counts) ? $status : 'unknown']++;

            if (! $m) {
                continue;
            }

            $checkedAt = $m->last_checked_at?->diffForHumans() ?? 'Belum pernah dicek';

            if ($status === MonitoringService::STATUS_UNREACHABLE) {
                $alerts[] = [
                    'device_id' => $dev->id,
                    'device_name' => $dev->name,
                    'type' => 'critical',
                    'message' => 'Perangkat tidak menjawab ICMP (unreachable)',
                    'timestamp' => $checkedAt,
                ];
            }

            if ($status === MonitoringService::STATUS_ERROR) {
                $alerts[] = [
                    'device_id' => $dev->id,
                    'device_name' => $dev->name,
                    'type' => 'critical',
                    'message' => 'Monitoring error: alamat monitoring tidak valid atau pengecekan tidak dapat dijalankan',
                    'timestamp' => $checkedAt,
                ];
            }

            if ($status === MonitoringService::STATUS_DEGRADED) {
                $alerts[] = [
                    'device_id' => $dev->id,
                    'device_name' => $dev->name,
                    'type' => 'warning',
                    'message' => 'Perangkat menjawab ICMP tetapi metrik (RouterOS API/SNMP) gagal dibaca',
                    'timestamp' => $checkedAt,
                ];
            }

            if ($m->last_packet_loss_percent !== null && $m->last_packet_loss_percent > 0 && $status !== MonitoringService::STATUS_UNREACHABLE) {
                $alerts[] = [
                    'device_id' => $dev->id,
                    'device_name' => $dev->name,
                    'type' => 'warning',
                    'message' => "Packet loss terdeteksi: {$m->last_packet_loss_percent}%",
                    'timestamp' => $checkedAt,
                ];
            }

            if ($m->last_cpu_usage_percent > 80) {
                $alerts[] = [
                    'device_id' => $dev->id,
                    'device_name' => $dev->name,
                    'type' => 'warning',
                    'message' => "High CPU utilization detected: {$m->last_cpu_usage_percent}%",
                    'timestamp' => $checkedAt,
                ];
            }

            if ($m->last_ram_usage_percent > 85) {
                $alerts[] = [
                    'device_id' => $dev->id,
                    'device_name' => $dev->name,
                    'type' => 'warning',
                    'message' => "High Memory utilization detected: {$m->last_ram_usage_percent}%",
                    'timestamp' => $checkedAt,
                ];
            }
        }

        // Get latest Speedtest result
        $latestSpeedtest = SpeedtestResult::latest()->first();

        return Inertia::render('Monitoring/Index', [
            'devices' => $devices,
            'summary' => [
                'total' => $totalCount,
                'online' => $counts[MonitoringService::STATUS_ONLINE],
                'degraded' => $counts[MonitoringService::STATUS_DEGRADED],
                'unreachable' => $counts[MonitoringService::STATUS_UNREACHABLE],
                'error' => $counts[MonitoringService::STATUS_ERROR],
                'unknown' => $counts['unknown'],
                'onlinePercent' => $totalCount > 0
                    ? round(($counts[MonitoringService::STATUS_ONLINE] / $totalCount) * 100)
                    : 0,
            ],
            'alerts' => $alerts,
            'latestSpeedtest' => $latestSpeedtest,
        ]);
    }

    /**
     * Trigger a manual network-wide status and metric scan.
     */
    public function scanAll(Request $request)
    {
        $count = $this->monitoringService->scanAll();

        return redirect()->back()->with('success', "Health scan completed for {$count} device nodes successfully.");
    }

    /**
     * Trigger a gateway speed test check.
     */
    public function runSpeedtest(Request $request)
    {
        try {
            $result = $this->speedtestService->runTest();
        } catch (\Throwable $e) {
            // Pengukuran gagal berarti tidak ada hasil — tidak ada angka
            // pengganti yang disimpan.
            return redirect()->back()->with('error', "Speedtest gagal: {$e->getMessage()}");
        }

        return redirect()->back()->with('success', "Speedtest completed: DL {$result->download_speed_mbps} Mbps / UL {$result->upload_speed_mbps} Mbps");
    }

    /**
     * Show detailed performance metrics and history graphs for a device.
     */
    public function show($id): Response
    {
        $device = Device::with(['metrics', 'building', 'floor', 'room', 'rack', 'category', 'vendor'])
            ->findOrFail($id);

        // 24 siklus pindai TERBARU, lalu dibalik menjadi urutan waktu naik untuk
        // grafik. Mengambil `orderBy('checked_at', 'asc')->limit(24)` justru
        // memberi 24 log tertua, sehingga halaman ini akan menampilkan
        // pengukuran lama seolah-olah kondisi terkini.
        $logs = MonitoringLog::where('device_id', $id)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit(24)
            ->get()
            ->reverse()
            ->values();

        return Inertia::render('Monitoring/Show', [
            'device' => $device,
            'historyLogs' => $logs,
        ]);
    }
}
