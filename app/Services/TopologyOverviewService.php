<?php

namespace App\Services;

use Illuminate\Support\Collection;

class TopologyOverviewService
{
    /**
     * Build an operational overview from stored monitoring snapshots and the
     * topology graph. Missing telemetry remains explicit instead of becoming a
     * healthy-looking default.
     *
     * @param Collection<int, \App\Models\Device> $devices
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, array<string, mixed>> $links
     * @return array<string, mixed>
     */
    public function build(Collection $devices, array $nodes, array $links): array
    {
        $health = [
            'healthy' => 0,
            'degraded' => 0,
            'warning' => 0,
            'down' => 0,
            'unknown' => 0,
        ];

        foreach ($nodes as $node) {
            if (($node['is_internet'] ?? false) === true) {
                continue;
            }

            $health[$this->healthKey($node['status'] ?? null)]++;
        }

        $locations = collect($nodes)
            ->reject(fn (array $node) => ($node['is_internet'] ?? false) === true)
            ->groupBy(fn (array $node) => $node['building'] ?? null)
            ->map(function (Collection $locationNodes, $building): array {
                $types = $locationNodes->countBy('type');
                $status = $locationNodes->countBy(fn (array $node) => $this->healthKey($node['status'] ?? null));

                return [
                    'key' => $building ?: '__unknown__',
                    'name' => $building ?: 'Lokasi belum diisi',
                    'total_devices' => $locationNodes->count(),
                    'routers' => (int) ($types['router'] ?? 0),
                    'switches' => (int) ($types['switch'] ?? 0),
                    'access_points' => (int) ($types['access_point'] ?? 0),
                    'servers' => (int) ($types['server'] ?? 0),
                    'healthy' => (int) ($status['healthy'] ?? 0),
                    'degraded' => (int) ($status['degraded'] ?? 0),
                    'warning' => (int) ($status['warning'] ?? 0),
                    'down' => (int) ($status['down'] ?? 0),
                    'unknown' => (int) ($status['unknown'] ?? 0),
                ];
            })
            ->sortByDesc(fn (array $location) => $location['down'] * 1000 + $location['degraded'] * 100 + $location['total_devices'])
            ->values()
            ->all();

        $nodesById = collect($nodes)->keyBy('id');
        $incidents = collect($nodes)
            ->filter(fn (array $node) => in_array($node['status'] ?? null, ['unreachable', 'offline', 'error', 'degraded'], true))
            ->map(fn (array $node): array => $this->incidentFor($node, $nodesById, $links))
            ->sortByDesc(fn (array $incident) => $incident['severity'] === 'critical' ? 2 : 1)
            ->values()
            ->all();

        $affectedDevices = collect($incidents)->sum(fn (array $incident) => count($incident['affected_devices']));

        return [
            'health' => $health,
            'total_devices' => array_sum($health),
            'active_incidents' => count($incidents),
            'affected_devices' => $affectedDevices,
            'locations' => $locations,
            'incidents' => $incidents,
            'evidence_scope' => [
                'telemetry' => 'device_metrics',
                'relationships' => collect($links)->where('inferred', false)->count(),
                'inferred_relationships' => collect($links)->where('inferred', true)->count(),
            ],
        ];
    }

    private function healthKey(?string $status): string
    {
        return match ($status) {
            'online' => 'healthy',
            'degraded' => 'degraded',
            'unreachable', 'offline' => 'down',
            'error' => 'warning',
            default => 'unknown',
        };
    }

    /**
     * @param array<string, mixed> $node
     * @param Collection<string, array<string, mixed>> $nodesById
     * @param array<int, array<string, mixed>> $links
     */
    private function incidentFor(array $node, Collection $nodesById, array $links): array
    {
        $status = $node['status'] ?? 'unknown';
        $severity = in_array($status, ['unreachable', 'offline'], true) ? 'critical' : 'warning';
        $lastSeen = $node['last_checked_at'] ?? null;
        $evidence = match ($status) {
            'unreachable', 'offline' => ['ICMP tidak menjawab pada pemeriksaan terakhir'],
            'degraded' => ['ICMP menjawab, tetapi telemetry management tidak lengkap'],
            'error' => ['Pemindaian tidak dapat dijalankan atau konfigurasi monitoring bermasalah'],
            default => [],
        };

        $affected = collect($links)
            ->filter(fn (array $link) => ($link['inferred'] ?? false) === false)
            ->map(function (array $link) use ($node, $nodesById): ?array {
                // The graph direction is parent/uplink -> downstream. Do not
                // reverse it or a child incident would claim its parent.
                $otherId = $link['source'] === ($node['id'] ?? null) ? $link['target'] : null;
                $other = $otherId ? $nodesById->get($otherId) : null;

                if ($other === null || ! in_array($other['status'] ?? null, ['unreachable', 'offline'], true)) {
                    return null;
                }

                return [
                    'id' => $other['db_id'] ?? null,
                    'name' => $other['name'] ?? 'Perangkat tidak dikenal',
                    'status' => $other['status'],
                ];
            })
            ->filter()
            ->unique('id')
            ->values()
            ->all();

        if ($affected !== []) {
            $evidence[] = 'Perangkat downstream pada relasi terverifikasi ikut unreachable';
        }

        return [
            'id' => 'device-' . ($node['id'] ?? 'unknown'),
            'root_device_id' => $node['db_id'] ?? null,
            'device' => $node['name'] ?? 'Perangkat tidak dikenal',
            'status' => $status,
            'severity' => $severity,
            'location' => collect([$node['building'] ?? null, $node['room'] ?? null])->filter()->implode(' · ') ?: 'Lokasi belum diisi',
            'last_seen' => $lastSeen,
            'affected_devices' => $affected,
            'probable_root_cause' => $affected === [] ? null : 'Kemungkinan parent/uplink atau perangkat root; physical cause belum dapat dipastikan',
            'evidence' => $evidence,
            'relationship_evidence' => 'Belum tersedia dari data topology yang terverifikasi',
        ];
    }
}
