<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\MikrotikService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MikrotikWebController extends Controller
{
    protected MikrotikService $mikrotik;

    public function __construct(MikrotikService $mikrotik)
    {
        $this->mikrotik = $mikrotik;
    }

    /**
     * Main RouterOS Explorer page — renders the Inertia view with initial data.
     */
    public function index()
    {
        $connection = $this->mikrotik->testConnection();

        return Inertia::render('Mikrotik/Explorer', [
            'routerConfig' => [
                'host' => config('services.mikrotik.host'),
                'port' => config('services.mikrotik.port'),
                'user' => config('services.mikrotik.user'),
                'ssl'  => config('services.mikrotik.ssl'),
            ],
            'connection' => $connection,
            'systemMetrics' => $connection['success'] ? $this->mikrotik->getSystemMetrics() : null,
            'ipAddresses' => $connection['success'] ? $this->mikrotik->getIpAddresses() : [],
            'routes' => $connection['success'] ? $this->mikrotik->getRoutes() : [],
            'users' => $connection['success'] ? $this->mikrotik->getUsers() : [],
            'packages' => $connection['success'] ? $this->mikrotik->getSystemPackages() : [],
            'dns' => $connection['success'] ? $this->mikrotik->getDnsConfig() : [],
        ]);
    }

    /**
     * JSON API: Refresh system metrics (CPU, RAM, Temp, Bandwidth).
     */
    public function refreshMetrics()
    {
        return response()->json($this->mikrotik->getSystemMetrics());
    }

    /**
     * JSON API: Fetch IP addresses.
     */
    public function ipAddresses()
    {
        return response()->json($this->mikrotik->getIpAddresses());
    }

    /**
     * JSON API: Fetch routing table.
     */
    public function routes()
    {
        return response()->json($this->mikrotik->getRoutes());
    }

    /**
     * JSON API: Fetch firewall filter rules.
     */
    public function firewallFilter()
    {
        return response()->json($this->mikrotik->getFirewallFilter());
    }

    /**
     * JSON API: Fetch NAT rules.
     */
    public function natRules()
    {
        return response()->json($this->mikrotik->getNatRules());
    }

    /**
     * JSON API: Fetch active hotspot users.
     */
    public function hotspotActive()
    {
        return response()->json($this->mikrotik->getHotspotActive());
    }

    /**
     * JSON API: Fetch DHCP leases.
     */
    public function dhcpLeases()
    {
        return response()->json($this->mikrotik->getDhcpLeases());
    }

    /**
     * JSON API: Fetch ARP table.
     */
    public function arpTable()
    {
        return response()->json($this->mikrotik->getArpTable());
    }

    /**
     * JSON API: Fetch system logs.
     */
    public function logs(Request $request)
    {
        $limit = min((int) $request->get('limit', 50), 200);
        return response()->json($this->mikrotik->getLogs(null, $limit));
    }

    /**
     * JSON API: Fetch network neighbors.
     */
    public function neighbors()
    {
        return response()->json($this->mikrotik->getNeighbors());
    }

    /**
     * JSON API: Fetch simple queues.
     */
    public function queues()
    {
        return response()->json($this->mikrotik->getQueues());
    }

    /**
     * JSON API: Fetch wireless clients.
     */
    public function wirelessClients()
    {
        return response()->json($this->mikrotik->getWirelessClients());
    }

    /**
     * JSON API: Fetch PPP active connections.
     */
    public function pppActive()
    {
        return response()->json($this->mikrotik->getPppActive());
    }

    /**
     * JSON API: Fetch DNS configuration.
     */
    public function dnsConfig()
    {
        return response()->json($this->mikrotik->getDnsConfig());
    }
}
