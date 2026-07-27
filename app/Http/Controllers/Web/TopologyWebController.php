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

    public function index()
    {
        return Inertia::render('Topology/Map', [
            'topologyData' => $this->buildTopologyGraph(),
        ]);
    }

    public function graphData()
    {
        return response()->json($this->buildTopologyGraph());
    }

    private function buildTopologyGraph(): array
    {
        // Fetch all inventory devices
        $dbDevices = Device::with(['category', 'building', 'vendor', 'room', 'metrics'])->get();

        // Fetch live MikroTik discovery data
        $connection = $this->mikrotik->testConnection();
        $neighbors = $connection['success'] ? $this->mikrotik->getNeighbors() : [];
        $ipAddresses = $connection['success'] ? $this->mikrotik->getIpAddresses() : [];

        $nodes = [];
        $links = [];
        $existingNodeKeys = [];

        // 1. Add Core Router (The MikroTik RB450Gx4 itself as Gateway Node)
        $coreRouterId = 'core-router-mikrotik';
        $nodes[] = [
            'id'          => $coreRouterId,
            'name'        => $connection['identity'] ?? 'MikroTik Core Router',
            'hostname'    => $connection['identity'] ?? 'RB450Gx4',
            'ip'          => config('services.mikrotik.host'),
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
        $existingNodeKeys[config('services.mikrotik.host')] = $coreRouterId;

        // 2. Map Database Devices into Nodes
        foreach ($dbDevices as $dev) {
            $nodeId = 'db-device-' . $dev->id;
            $ip = $dev->ip_address;

            // Skip if this device is the core router itself
            if ($ip === config('services.mikrotik.host')) {
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
            if ($node['id'] === $coreRouterId) continue;

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
                    'source_interface' => 'ether2/3/4',
                    'target_interface' => 'eth0',
                    'status'           => $node['status'] === 'online' ? 'active' : 'offline',
                    'protocol'         => 'IP Routing',
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
