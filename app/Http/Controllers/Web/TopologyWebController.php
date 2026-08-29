<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\MikrotikService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TopologyWebController extends Controller
{
    protected MikrotikService $mikrotik;

    public function __construct(MikrotikService $mikrotik)
    {
        $this->mikrotik = $mikrotik;
    }

    /**
     * Graf topologi dibangun dari discovery MikroTik yang hidup (testConnection,
     * getNeighbors, getIpAddresses), jadi sepuluhan detik per request. Datanya
     * dikirim sebagai deferred prop supaya kanvas topologi langsung tampil saat
     * menu diklik, bukan menahan navigasi sampai router menjawab.
     */
    public function index(Request $request)
    {
        $host = $request->query('host');

        return Inertia::render('Topology/Map', [
            'topologyData' => Inertia::defer(fn() => $this->buildTopologyGraph($host)),
        ]);
    }

    public function graphData(Request $request)
    {
        return response()->json($this->buildTopologyGraph($request->query('host')));
    }

    private function buildTopologyGraph(?string $targetHost = null): array
    {
        // Fetch all inventory devices
        $dbDevices = Device::with(['category', 'building', 'vendor', 'room', 'metrics'])->get();

        // Find primary router from DB Inventory if targetHost is null
        if (!$targetHost) {
            $firstRouter = $dbDevices->first(function ($device) {
                return $device->ip_address && (
                    str_contains(strtolower($device->category?->name ?? ''), 'router') ||
                    str_contains(strtolower($device->category?->name ?? ''), 'mikrotik') ||
                    str_contains(strtolower($device->name), 'mikrotik')
                );
            });
            $targetHost = $firstRouter?->ip_address ?? config('services.mikrotik.host');
        }

        // Fetch live MikroTik discovery data for target host
        $connection = $this->mikrotik->testConnection($targetHost);
        $neighbors = $connection['success'] ? $this->mikrotik->getNeighbors($targetHost) : [];
        $ipAddresses = $connection['success'] ? $this->mikrotik->getIpAddresses($targetHost) : [];

        $nodes = [];
        $links = [];
        $existingNodeKeys = [];

        // 0. Simpul internet/WAN sebagai jangkar topologi. Uplink ISP tidak
        // dimonitor oleh CIMS, jadi statusnya 'unknown' — bukan diklaim online,
        // dan alamat WAN-nya tidak dikarang.
        $ispNodeId = 'isp-internet-cloud';
        $nodes[] = [
            'id'          => $ispNodeId,
            'name'        => 'Internet / Uplink ISP',
            'hostname'    => null,
            'ip'          => null,
            'type'        => 'internet',
            'category'    => 'Internet Gateway',
            'vendor'      => null,
            'status'      => 'unknown',
            'is_internet' => true,
            'is_core'     => false,
            'is_inferred' => true,
            'building'    => 'Di luar cakupan monitoring CIMS',
            'model'       => null,
        ];

        // 1. Core router: seluruh identitasnya berasal dari discovery nyata.
        // Kalau discovery gagal, field-nya dibiarkan kosong dan penyebabnya
        // dibawa ke UI — tidak diisi model/versi karangan.
        $coreDevice = $dbDevices->first(fn ($device) => $device->ip_address === $targetHost);
        $coreRouterId = 'core-router-mikrotik';
        $nodes[] = [
            'id'          => $coreRouterId,
            'name'        => $connection['identity'] ?? $coreDevice?->name ?? 'Core Router',
            'hostname'    => $connection['identity'] ?? $coreDevice?->hostname,
            'ip'          => $targetHost,
            'type'        => 'router',
            'category'    => 'Core Router',
            'vendor'      => $coreDevice?->vendor?->name ?? 'MikroTik',
            'status'      => $connection['success']
                ? 'online'
                : ($coreDevice?->metrics?->last_ping_status ?? 'error'),
            'error'       => $connection['success'] ? null : ($connection['error'] ?? null),
            'is_core'     => true,
            'building'    => $coreDevice?->building?->name,
            'model'       => $connection['board'] ?? $coreDevice?->model,
            'version'     => $connection['version'] ?? null,
            'interfaces'  => count($ipAddresses),
        ];
        $existingNodeKeys[$targetHost] = $coreRouterId;

        // Uplink ke ISP tidak ikut didiscovery, jadi ditandai belum terverifikasi.
        $links[] = [
            'id'               => 'link-isp-core',
            'source'           => $ispNodeId,
            'target'           => $coreRouterId,
            'source_interface' => null,
            'target_interface' => null,
            'status'           => 'unknown',
            'protocol'         => 'Uplink WAN (belum diverifikasi)',
            'inferred'         => true,
        ];

        // 2. Map Database Devices into Nodes
        foreach ($dbDevices as $dev) {
            $nodeId = 'db-device-' . $dev->id;
            $ip = $dev->ip_address;

            // Skip if this device is the core router itself
            if ($ip === $targetHost) {
                continue;
            }

            $catName = strtolower($dev->category?->name ?? 'other');
            $type = 'other';
            if (str_contains($catName, 'router')) $type = 'router';
            elseif (str_contains($catName, 'switch')) $type = 'switch';
            elseif (str_contains($catName, 'access point') || str_contains($catName, 'ap') || str_contains($catName, 'wireless')) $type = 'access_point';
            elseif (str_contains($catName, 'server') || str_contains($catName, 'proxmox')) $type = 'server';
            elseif (str_contains($catName, 'firewall')) $type = 'firewall';

            $nodes[] = [
                'id'            => $nodeId,
                'db_id'         => $dev->id,
                'name'          => $dev->name,
                'hostname'      => $dev->hostname ?? $dev->name,
                'ip'            => $dev->ip_address,
                'mac'           => $dev->mac_address,
                'type'          => $type,
                'category'      => $dev->category?->name ?? 'Uncategorized',
                'vendor'        => $dev->vendor?->name ?? 'Unknown',
                // Status simpul mengikuti hasil monitoring nyata. Perangkat yang
                // belum pernah dicek ditandai 'unknown', bukan diasumsikan hidup.
                'status'        => $dev->metrics?->last_ping_status ?? 'unknown',
                'last_checked_at' => $dev->metrics?->last_checked_at,
                'inventory_status' => $dev->status,
                'is_core'       => false,
                'building'      => $dev->building?->name ?? 'Unassigned',
                'room'          => $dev->room?->name ?? '-',
                'model'         => $dev->model ?? '-',
                'serial_number' => $dev->serial_number ?? '-',
            ];

            if ($ip) {
                $existingNodeKeys[$ip] = $nodeId;
            }
        }

        // 3. Process MikroTik Neighbors to draw Links & auto-discover unknown nodes
        foreach ($neighbors as $idx => $nb) {
            $nbIp = $nb['address'] ?? null;
            $nbIdentity = $nb['identity'] ?? ('Neighbor-' . ($idx + 1));
            $nbBoard = $nb['board'] ?? $nb['platform'] ?? 'MikroTik Device';
            $viaInterface = $nb['interface'] ?? null;

            $targetNodeId = null;

            // Check if neighbor IP matches an existing node in DB
            if ($nbIp && isset($existingNodeKeys[$nbIp])) {
                $targetNodeId = $existingNodeKeys[$nbIp];
            } else {
                // Auto-discover new node from MNDP/CDP!
                $targetNodeId = 'discovered-neighbor-' . $idx;

                $type = 'switch';
                if (str_contains(strtolower($nbIdentity), 'ap') || str_contains(strtolower($nbBoard), 'wireless') || str_contains(strtolower($nbIdentity), 'balkon')) {
                    $type = 'access_point';
                } elseif (str_contains(strtolower($nbBoard), 'router') || str_contains(strtolower($nbBoard), 'rb')) {
                    $type = 'router';
                }

                $nodes[] = [
                    'id'            => $targetNodeId,
                    'name'          => $nbIdentity,
                    'hostname'      => $nbIdentity,
                    'ip'            => $nbIp ?? 'Auto-Discovered',
                    'mac'           => $nb['mac'] ?? '-',
                    'type'          => $type,
                    'category'      => 'Discovered Neighbor (MNDP)',
                    'vendor'        => 'MikroTik',
                    'status'        => 'online',
                    'is_core'       => false,
                    'is_discovered' => true,
                    'building'      => $viaInterface ? 'Terdeteksi via ' . $viaInterface : 'Terdeteksi via MNDP',
                    'model'         => $nbBoard,
                ];

                if ($nbIp) {
                    $existingNodeKeys[$nbIp] = $targetNodeId;
                }
            }

            // Add Link between Core Router and Target Node
            $links[] = [
                'id'            => 'link-' . $coreRouterId . '-' . $targetNodeId,
                'source'        => $coreRouterId,
                'target'        => $targetNodeId,
                'source_interface' => $viaInterface,
                'target_interface' => 'uplink',
                'status'        => 'active',
                'protocol'      => 'MNDP/CDP',
            ];
        }

        // 4. Perangkat inventaris yang tidak muncul di discovery MNDP tetap
        // ditampilkan, tetapi relasinya ditandai belum terverifikasi — link ini
        // berasal dari data inventaris, bukan dari hasil discovery jaringan.
        foreach ($nodes as $node) {
            if ($node['id'] === $coreRouterId || $node['id'] === $ispNodeId) continue;

            // Check if node already has a link
            $hasLink = false;
            foreach ($links as $l) {
                if ($l['source'] === $node['id'] || $l['target'] === $node['id']) {
                    $hasLink = true;
                    break;
                }
            }

            if (!$hasLink) {
                $links[] = [
                    'id'               => 'link-auto-' . $node['id'],
                    'source'           => $coreRouterId,
                    'target'           => $node['id'],
                    'source_interface' => null,
                    'target_interface' => null,
                    'status'           => 'unknown',
                    'protocol'         => 'Relasi inventaris (belum diverifikasi)',
                    'inferred'         => true,
                ];
            }
        }

        return [
            'nodes' => $nodes,
            'links' => $links,
            'connection' => [
                'host' => $targetHost,
                'success' => (bool) ($connection['success'] ?? false),
                'error' => $connection['success'] ? null : ($connection['error'] ?? null),
            ],
            'stats' => [
                'total_nodes'          => count($nodes),
                'core_routers'         => 1,
                'discovered_neighbors' => count(array_filter($nodes, fn($n) => $n['is_discovered'] ?? false)),
                'online_nodes'         => count(array_filter($nodes, fn($n) => $n['status'] === 'online')),
                'total_links'          => count($links),
                'unverified_links'     => count(array_filter($links, fn($l) => $l['inferred'] ?? false)),
            ]
        ];
    }
}
