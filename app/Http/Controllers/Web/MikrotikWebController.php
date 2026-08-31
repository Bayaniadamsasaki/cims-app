<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\MikrotikContainerSpeedtestService;
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
     *
     * Halaman ini butuh sampai delapan round-trip ke RouterOS (testConnection,
     * metrics, ip, route, user, package, dns) yang totalnya bisa puluhan detik.
     * Semuanya dikirim sebagai deferred prop supaya klik menu langsung membuka
     * halaman, lalu data router menyusul dalam satu request lanjutan.
     */
    public function index(Request $request)
    {
        $envHost = config('services.mikrotik.host');
        $targetHost = $request->query('host') ?? $envHost;

        // Seluruh prop deferred berada di grup yang sama, jadi closure ini
        // dievaluasi dalam satu request; hasil probe di-memoize agar router
        // benar-benar hanya dihubungi sekali per kunjungan.
        $probe = null;
        $live = function () use (&$probe, $envHost, $targetHost) {
            return $probe ??= $this->probeRouters($envHost, $targetHost);
        };

        return Inertia::render('Mikrotik/Explorer', [
            'routerConfig' => [
                'host' => $targetHost,
                'port' => config('services.mikrotik.port'),
                'user' => config('services.mikrotik.user'),
                'ssl'  => config('services.mikrotik.ssl'),
            ],
            'selectedHost'     => $targetHost,
            'availableRouters' => Inertia::defer(fn() => $live()['availableRouters']),
            'selectedRouter'   => Inertia::defer(fn() => $live()['selectedRouter']),
            'connection'       => Inertia::defer(fn() => $live()['connection']),
            'systemMetrics'    => Inertia::defer(fn() => $live()['systemMetrics']),
            'ipAddresses'      => Inertia::defer(fn() => $live()['ipAddresses']),
            'routes'           => Inertia::defer(fn() => $live()['routes']),
            'users'            => Inertia::defer(fn() => $live()['users']),
            'packages'         => Inertia::defer(fn() => $live()['packages']),
            'dns'              => Inertia::defer(fn() => $live()['dns']),
        ]);
    }

    /**
     * Kumpulkan daftar router yang tersedia beserta seluruh data live milik
     * `$targetHost`. Dipanggil dari closure deferred pada index(), bukan dari
     * jalur render awal.
     */
    private function probeRouters(?string $envHost, ?string $targetHost): array
    {
        $availableRouters = [];
        $addedIps = [];

        // 1. Router dari environment tidak lagi diprobe terpisah di sini: hasilnya
        //    dulu ditaruh di $defaultRouter yang tidak pernah dipakai, sehingga
        //    hanya menambah satu round-trip ke RouterOS. Bila $targetHost memang
        //    router env, datanya tetap didapat dari testConnection di langkah 4.

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

        return [
            'availableRouters' => $availableRouters,
            'selectedRouter'   => $selectedRouter,
            'connection'       => $connection,
            'systemMetrics'    => $connection['success'] ? $this->mikrotik->getSystemMetrics($targetHost) : null,
            'ipAddresses'      => $connection['success'] ? $this->mikrotik->getIpAddresses($targetHost) : [],
            'routes'           => $connection['success'] ? $this->mikrotik->getRoutes($targetHost) : [],
            'users'            => $connection['success'] ? $this->mikrotik->getUsers($targetHost) : [],
            'packages'         => $connection['success'] ? $this->mikrotik->getSystemPackages($targetHost) : [],
            'dns'              => $connection['success'] ? $this->mikrotik->getDnsConfig($targetHost) : [],
        ];
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
     * JSON API: Peta keikutsertaan OSPF per interface — mana yang sudah routing
     * OSPF, mana yang belum, dan mana yang memang tidak bisa.
     *
     * Sengaja tidak ikut prop deferred halaman: pengumpulannya butuh sampai
     * delapan round-trip ke RouterOS, dan hanya relevan saat tab OSPF dibuka.
     */
    public function ospf(Request $request)
    {
        return response()->json($this->mikrotik->getOspfCoverage($this->getTargetHost($request)));
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

    /**
     * JSON API: Kesiapan speedtest container di router — ada/tidaknya container,
     * apakah logging-nya aktif, putaran yang sedang berjalan, dan hasil terakhir.
     */
    public function speedtestStatus(Request $request, MikrotikContainerSpeedtestService $speedtest)
    {
        return response()->json($speedtest->status($this->getTargetHost($request)));
    }

    /**
     * JSON API: Mulai satu putaran speedtest di router.
     *
     * Satu-satunya endpoint MikroTik di controller ini yang MENULIS ke router
     * sekaligus menjenuhkan uplink kampus selama puluhan detik. Izin dan
     * pembatasan lajunya dipasang di routes/web.php, bukan di sini.
     *
     * Kegagalan dijawab 422 dengan pesan aslinya: hampir semuanya adalah kondisi
     * router yang bisa diperbaiki operator (container belum ada, logging belum
     * aktif, putaran lain masih jalan), jadi pesannya justru yang paling berguna.
     */
    public function speedtestStart(Request $request, MikrotikContainerSpeedtestService $speedtest)
    {
        try {
            return response()->json($speedtest->start($this->getTargetHost($request)));
        } catch (\Throwable $e) {
            return response()->json(['state' => 'failed', 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * JSON API: Perkembangan putaran speedtest yang sedang berjalan. Dipanggil
     * berkala oleh halaman; hanya membaca /log dan /container.
     */
    public function speedtestPoll(Request $request, MikrotikContainerSpeedtestService $speedtest)
    {
        return response()->json($speedtest->poll($this->getTargetHost($request)));
    }

    /**
     * JSON API: Hentikan container speedtest — padanan "Stop" di menu Winbox.
     *
     * Ini jalan keluar untuk putaran yang menggantung. Tanpanya container yang
     * macet hanya bisa dimatikan dari Winbox, dan sampai itu dilakukan tidak ada
     * putaran baru yang boleh dimulai.
     */
    public function speedtestStop(Request $request, MikrotikContainerSpeedtestService $speedtest)
    {
        try {
            return response()->json($speedtest->stop($this->getTargetHost($request)));
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * JSON API: Stop lalu Start dalam satu tindakan — padanan "Restart" di Winbox.
     *
     * Dijawab dengan keadaan lengkap (bukan hanya putarannya) karena setelah
     * restart status container di halaman sudah tidak sama lagi.
     */
    public function speedtestRestart(Request $request, MikrotikContainerSpeedtestService $speedtest)
    {
        $host = $this->getTargetHost($request);

        try {
            $speedtest->restart($host);

            // status() membaca putaran yang baru saja ditulis restart() ke cache,
            // jadi tidak perlu digabung manual.
            return response()->json($speedtest->status($host));
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * JSON API: Baris log bertopik container — padanan tab "Log" di Winbox.
     *
     * Hanya membaca /log, jadi tidak digolongkan sebagai tindakan tulis: justru
     * inilah yang perlu dibuka lebih dulu ketika container gagal, sebelum
     * memutuskan apa pun.
     */
    public function speedtestLog(Request $request, MikrotikContainerSpeedtestService $speedtest)
    {
        return response()->json([
            'lines' => $speedtest->containerLog($this->getTargetHost($request)),
        ]);
    }
}
