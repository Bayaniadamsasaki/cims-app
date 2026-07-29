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

    public function index(Request $request)
    {
        return Inertia::render('Topology/Map', [
            'topologyData' => $this->buildTopologyGraph($request->query('host')),
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

        // 0. Add ISP / Internet Gateway Node at the top
        $ispNodeId = 'isp-internet-cloud';
        $nodes[] = [
            'id'          => $ispNodeId,
            'name'        => 'ISP Source (Astinet / WAN)',
            'hostname'    => 'Astinet Internet Gateway',
            'ip'          => '116.58.127.23 (WAN)',
            'type'        => 'internet',
            'category'    => 'Internet Gateway',
            'vendor'      => 'ASTINET / Telkom',
            'status'      => 'online',
            'is_internet' => true,
            'is_core'     => false,
            'building'    => 'ISP Uplink Fiber',
            'model'       => 'Internet Fiber Gateway',
        ];

        // 1. Add Core Router (The MikroTik RB450Gx4 itself as Core Router)
        $coreRouterId = 'core-router-mikrotik';
        $nodes[] = [
            'id'          => $coreRouterId,
            'name'        => $connection['identity'] ?? 'MikroTik Core Router',
            'hostname'    => $connection['identity'] ?? 'RB450Gx4',
            'ip'          => $targetHost,
            'type'        => 'router',
            'category'    => 'Core Router',
            'vendor'      => 'MikroTik',
            'status'      => $connection['success'] ? 'online' : 'offline',
            'is_core'     => true,
            'building'    => 'Gedung Rektorat / Data Center',
            'model'       => $connection['board'] ?? 'RB450Gx4',
            'version'     => $connection['version'] ?? '7.23.2',
            'interfaces'  => count($ipAddresses),
        ];
        $existingNodeKeys[$targetHost] = $coreRouterId;

        // Link ISP Cloud to Core Router
        $links[] = [
            'id'               => 'link-isp-core',
            'source'           => $ispNodeId,
            'target'           => $coreRouterId,
            'source_interface' => 'WAN (ISP)',
            'target_interface' => 'ether1_WAN',
            'status'           => 'active',
            'protocol'         => 'BGP / Static WAN Route',
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
                'status'        => strtolower($dev->status ?? 'online'),
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
            $viaInterface = $nb['interface'] ?? 'ether1';

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
                    'building'      => 'Discovered via ' . $viaInterface,
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

        // 4. Fallback links for DB devices not linked by MNDP (connect them to Core Router or closest subnet)
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
                    'status'           => $node['status'] === 'online' ? 'active' : 'offline',
                    'protocol'         => 'IP Network Link',
                ];
            }
        }

        return [
            'nodes' => $nodes,
            'links' => $links,
            'stats' => [
                'total_nodes'          => count($nodes),
                'core_routers'         => 1,
                'discovered_neighbors' => count(array_filter($nodes, fn($n) => $n['is_discovered'] ?? false)),
                'online_nodes'         => count(array_filter($nodes, fn($n) => $n['status'] === 'online')),
                'total_links'          => count($links),
            ]
        ];
    }
}
