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

        // Calculate summary metrics
        $totalDevices = count($devices);
        $onlineDevices = count(array_filter($devices, fn($d) => $d['status'] === 'online'));
        $totalClients = array_sum(array_column($devices, 'client_count'));

        return Inertia::render('Ruijie/Explorer', [
            'ruijieConfig' => [
                'appId' => config('services.ruijie.app_id'),
                'baseUrl' => config('services.ruijie.base_url'),
            ],
            'connection' => $connection,
            'summary' => [
                'totalDevices' => $totalDevices,
                'onlineDevices' => $onlineDevices,
                'offlineDevices' => $totalDevices - $onlineDevices,
                'totalClients' => $totalClients,
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
