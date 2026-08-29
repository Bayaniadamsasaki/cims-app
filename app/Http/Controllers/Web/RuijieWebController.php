<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\RuijieService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RuijieWebController extends Controller
{
    protected RuijieService $ruijie;

    public function __construct(RuijieService $ruijie)
    {
        $this->ruijie = $ruijie;
    }

    /**
     * Display main Ruijie Reyee Cloud Live API Explorer page.
     */
    public function index()
    {
        $connection = $this->ruijie->testConnection();
        $devices = $this->ruijie->getDeviceList();
        $wirelessClients = $this->ruijie->getWirelessClients();
        $alarms = $this->ruijie->getAlarms();

        // Ringkasan dihitung dari data yang benar-benar dikembalikan sumbernya.
        // Ruijie Cloud melaporkan 'online'/'offline', sedangkan perangkat yang
        // hanya ada di inventaris memakai hasil MonitoringService
        // ('degraded'/'unreachable'/'error'). Hanya perangkat berstatus
        // 'unknown' (belum pernah dicek) yang dihitung sebagai belum dilaporkan,
        // dan jumlah klien hanya menjumlahkan perangkat yang memang
        // melaporkannya.
        $totalDevices = count($devices);
        $countStatus = fn (array $statuses) => count(
            array_filter($devices, fn ($d) => in_array($d['status'] ?? null, $statuses, true))
        );

        $onlineDevices = $countStatus(['online']);
        $offlineDevices = $countStatus(['offline', 'unreachable']);
        $degradedDevices = $countStatus(['degraded']);
        $errorDevices = $countStatus(['error']);
        $reportedClients = array_filter(array_column($devices, 'client_count'), fn ($c) => $c !== null);

        return Inertia::render('Ruijie/Explorer', [
            'ruijieConfig' => [
                'appId' => config('services.ruijie.app_id'),
                'baseUrl' => config('services.ruijie.base_url'),
            ],
            'connection' => $connection,
            'summary' => [
                'totalDevices' => $totalDevices,
                'onlineDevices' => $onlineDevices,
                'offlineDevices' => $offlineDevices,
                'degradedDevices' => $degradedDevices,
                'errorDevices' => $errorDevices,
                'unknownDevices' => max(0, $totalDevices - $onlineDevices - $offlineDevices - $degradedDevices - $errorDevices),
                'totalClients' => count($reportedClients) > 0 ? array_sum($reportedClients) : null,
            ],
            'devices' => $devices,
            'wirelessClients' => $wirelessClients,
            'alarms' => $alarms,
        ]);
    }

    /**
     * JSON API: Test Ruijie Cloud API connection.
     */
    public function testConnection()
    {
        return response()->json($this->ruijie->testConnection());
    }

    /**
     * JSON API: Fetch Ruijie Cloud device list.
     */
    public function devices()
    {
        return response()->json($this->ruijie->getDeviceList());
    }

    /**
     * JSON API: Fetch connected wireless clients.
     */
    public function wirelessClients()
    {
        return response()->json($this->ruijie->getWirelessClients());
    }

    /**
     * JSON API: Fetch active network alarms.
     */
    public function alarms()
    {
        return response()->json($this->ruijie->getAlarms());
    }
}
