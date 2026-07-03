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

        // Calculate average metrics
        $onlineCount = $devices->where('status', 'active')->count();
        $totalCount = $devices->count();
        
        // Load recent historical alerts
        $alerts = [];
        foreach ($devices as $dev) {
            $m = $dev->metrics;
            if ($m) {
                if ($m->last_ping_status === 'offline') {
                    $alerts[] = [
                        'device_id' => $dev->id,
                        'device_name' => $dev->name,
                        'type' => 'critical',
                        'message' => "Device is currently OFFLINE / unreachable",
                        'timestamp' => $m->last_checked_at?->diffForHumans() ?? 'Just now'
                    ];
                }
                if ($m->last_cpu_usage_percent > 80) {
                    $alerts[] = [
                        'device_id' => $dev->id,
                        'device_name' => $dev->name,
                        'type' => 'warning',
                        'message' => "High CPU utilization detected: {$m->last_cpu_usage_percent}%",
                        'timestamp' => $m->last_checked_at?->diffForHumans() ?? 'Just now'
                    ];
                }
                if ($m->last_ram_usage_percent > 85) {
                    $alerts[] = [
                        'device_id' => $dev->id,
                        'device_name' => $dev->name,
                        'type' => 'warning',
                        'message' => "High Memory utilization detected: {$m->last_ram_usage_percent}%",
                        'timestamp' => $m->last_checked_at?->diffForHumans() ?? 'Just now'
                    ];
                }
            }
        }

        // Get latest Speedtest result
        $latestSpeedtest = SpeedtestResult::latest()->first();

        return Inertia::render('Monitoring/Index', [
            'devices' => $devices,
            'summary' => [
                'total' => $totalCount,
                'online' => $onlineCount,
                'offline' => $totalCount - $onlineCount,
                'onlinePercent' => $totalCount > 0 ? round(($onlineCount / $totalCount) * 100) : 0,
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
        $result = $this->speedtestService->runTest();
        return redirect()->back()->with('success', "Speedtest completed: DL {$result->download_speed_mbps} Mbps / UL {$result->upload_speed_mbps} Mbps");
    }

    /**
     * Show detailed performance metrics and history graphs for a device.
     */
    public function show($id): Response
    {
        $device = Device::with(['metrics', 'building', 'category'])->findOrFail($id);
        
        // Fetch 24 hours of history logs
        $logs = MonitoringLog::where('device_id', $id)
            ->orderBy('checked_at', 'asc')
            ->limit(24)
            ->get();

        return Inertia::render('Monitoring/Show', [
            'device' => $device,
            'historyLogs' => $logs
        ]);
    }
}
