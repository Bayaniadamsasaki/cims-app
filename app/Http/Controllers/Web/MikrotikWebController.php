<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\MikrotikService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class MikrotikWebController extends Controller
{
    protected MikrotikService $mikrotik;

    public function __construct(MikrotikService $mikrotik)
    {
        $this->mikrotik = $mikrotik;
    }

    /**
     * Helper to get target router credentials or host from request.
     */
    protected function getTargetHost(Request $request): ?string
    {
        return $request->query('host');
    }

    /**
     * Main RouterOS Explorer page — renders the Inertia view with initial data.
     */
    public function index(Request $request)
    {
        $envHost = config('services.mikrotik.host');
        $availableRouters = [];
        $addedIps = [];

        // 1. Selalu sertakan router default dari environment
            // Ambil identity dan model router melalui API untuk dijadikan nilai default
            $coreConn = $this->mikrotik->testConnection($envHost);
            $routerName = $coreConn['success'] && isset($coreConn['identity']) ? $coreConn['identity'] : 'Core Router';
            $routerModel = $coreConn['success'] && isset($coreConn['board']) ? $coreConn['board'] : 'Unknown Model';
            $defaultRouter = [
                'id' => 'env-default',
                'name' => $routerName,
                'model' => $routerModel,
                'location' => 'Data Center',
                'ip' => $envHost,
            ];

        // 2. Ambil router dari database yang khusus vendor/kategori MikroTik
        $dbRouters = \App\Models\Device::with(['building', 'vendor', 'category'])
            ->where(function ($query) {
                $query->whereHas('vendor', function ($q) {
                    $q->where('name', 'like', '%mikrotik%');
                })->orWhereHas('category', function ($q) {
                    $q->where('name', 'like', '%mikrotik%');
                });
            })
            ->get();

        foreach ($dbRouters as $r) {
            if ($r->ip_address && !in_array($r->ip_address, $addedIps)) {
                $addedIps[] = $r->ip_address;
                $availableRouters[] = [
                    'id' => $r->id,
                    'name' => $r->name,
                    'model' => $r->model ?? 'MikroTik',
                    'location' => $r->building?->name ?? 'Main Site',
                    'ip' => $r->ip_address,
                ];
            }
        }

        // 3. Discovery from core (Cached for 5 mins)
        $discovered = Cache::remember('mikrotik_discovered_routers', 300, function () use ($envHost) {
            if (!$envHost) return [];
            $neighbors = $this->mikrotik->getNeighbors($envHost);
            $list = [];
            foreach ($neighbors as $nb) {
                if (!empty($nb['address'])) {
                    $list[] = [
                        'id' => 'discovered-nb-' . md5($nb['address']),
                        'name' => $nb['identity'] ?? ('Neighbor (' . $nb['address'] . ')'),
                        'model' => $nb['board'] ?? $nb['platform'] ?? 'MikroTik MNDP',
                        'location' => 'Auto-Discovered (' . ($nb['interface'] ?? 'eth') . ')',
                        'ip' => $nb['address'],
                    ];
                }
            }
            return $list;
        });

        foreach ($discovered as $nb) {
            if (!in_array($nb['ip'], $addedIps)) {
                $addedIps[] = $nb['ip'];
                $availableRouters[] = $nb;
            }
        }

        $targetHost = $request->query('host') ?? $envHost;
        $connection = $this->mikrotik->testConnection($targetHost);

        $selectedRouter = null;

        foreach ($availableRouters as $router) {
            if (($router['ip'] ?? null) === $targetHost) {
                $selectedRouter = $router;
                break;
            }
        }

        if ($connection['success']) {
            $connectionRouter = [
                'id' => 'selected-host-' . md5((string) $targetHost),
                'name' => $connection['identity'] ?? ($targetHost ?? 'Selected Router'),
                'model' => $connection['board'] ?? 'Unknown Model',
                'location' => 'Selected Target',
                'ip' => $targetHost,
            ];

            if ($selectedRouter) {
                $selectedRouter['name'] = $connectionRouter['name'];
                $selectedRouter['model'] = $connectionRouter['model'];
            } elseif ($targetHost) {
                $selectedRouter = $connectionRouter;
                $availableRouters[] = $connectionRouter;
            }
        } elseif (!$selectedRouter && $targetHost) {
            $selectedRouter = [
                'id' => 'selected-host-' . md5((string) $targetHost),
                'name' => $targetHost,
                'model' => 'Unknown Model',
                'location' => 'Selected Target',
                'ip' => $targetHost,
            ];
            $availableRouters[] = $selectedRouter;
        }

        return Inertia::render('Mikrotik/Explorer', [
            'routerConfig' => [
                'host' => $targetHost,
                'port' => config('services.mikrotik.port'),
                'user' => config('services.mikrotik.user'),
                'ssl'  => config('services.mikrotik.ssl'),
            ],
            'availableRouters' => $availableRouters,
            'selectedRouter'   => $selectedRouter,
            'selectedHost'     => $targetHost,
            'connection'       => $connection,
            'systemMetrics'    => $connection['success'] ? $this->mikrotik->getSystemMetrics($targetHost) : null,
            'ipAddresses'      => $connection['success'] ? $this->mikrotik->getIpAddresses($targetHost) : [],
            'routes'           => $connection['success'] ? $this->mikrotik->getRoutes($targetHost) : [],
            'users'            => $connection['success'] ? $this->mikrotik->getUsers($targetHost) : [],
            'packages'         => $connection['success'] ? $this->mikrotik->getSystemPackages($targetHost) : [],
            'dns'              => $connection['success'] ? $this->mikrotik->getDnsConfig($targetHost) : [],
        ]);
    }

    /**
     * JSON API: Refresh system metrics (CPU, RAM, Temp, Bandwidth).
     */
    public function refreshMetrics(Request $request)
    {
        return response()->json($this->mikrotik->getSystemMetrics($this->getTargetHost($request)));
    }

    /**
     * JSON API: Fetch IP addresses.
     */
    public function ipAddresses(Request $request)
    {
        return response()->json($this->mikrotik->getIpAddresses($this->getTargetHost($request)));
    }

    /**
     * JSON API: Fetch routing table.
     */
    public function routes(Request $request)
    {
        return response()->json($this->mikrotik->getRoutes($this->getTargetHost($request)));
    }

    /**
     * JSON API: Fetch firewall filter rules.
     */
    public function firewallFilter(Request $request)
    {
        return response()->json($this->mikrotik->getFirewallFilter($this->getTargetHost($request)));
    }

    /**
     * JSON API: Fetch NAT rules.
     */
    public function natRules(Request $request)
    {
        return response()->json($this->mikrotik->getNatRules($this->getTargetHost($request)));
    }

    /**
     * JSON API: Fetch active hotspot users.
     */
    public function hotspotActive(Request $request)
    {
        return response()->json($this->mikrotik->getHotspotActive($this->getTargetHost($request)));
    }

    /**
     * JSON API: Fetch DHCP leases.
     */
    public function dhcpLeases(Request $request)
    {
        return response()->json($this->mikrotik->getDhcpLeases($this->getTargetHost($request)));
    }

    /**
     * JSON API: Fetch ARP table.
     */
    public function arpTable(Request $request)
    {
        return response()->json($this->mikrotik->getArpTable($this->getTargetHost($request)));
    }

    /**
     * JSON API: Fetch system logs.
     */
    public function logs(Request $request)
    {
        $limit = min((int) $request->get('limit', 50), 200);
        return response()->json($this->mikrotik->getLogs($this->getTargetHost($request), $limit));
    }

    /**
     * JSON API: Fetch network neighbors.
     */
    public function neighbors(Request $request)
    {
        return response()->json($this->mikrotik->getNeighbors($this->getTargetHost($request)));
    }

    /**
     * JSON API: Fetch simple queues.
     */
    public function queues(Request $request)
    {
        return response()->json($this->mikrotik->getQueues($this->getTargetHost($request)));
    }

    /**
     * JSON API: Fetch wireless clients.
     */
    public function wirelessClients(Request $request)
    {
        return response()->json($this->mikrotik->getWirelessClients($this->getTargetHost($request)));
    }

    /**
     * JSON API: Fetch PPP active connections.
     */
    public function pppActive(Request $request)
    {
        return response()->json($this->mikrotik->getPppActive($this->getTargetHost($request)));
    }

    /**
     * JSON API: Fetch DNS configuration.
     */
    public function dnsConfig(Request $request)
    {
        return response()->json($this->mikrotik->getDnsConfig($this->getTargetHost($request)));
    }
}
