<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\PhysicalLink;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class PhysicalLinkPersistenceService
{
    /**
     * Persist live RouterOS neighbor observations without deleting older links.
     * The neighbor endpoint identifies the local interface, but not the remote
     * interface, so interface_b_id intentionally remains nullable.
     *
     * @param array<int, array<string, mixed>> $neighbors
     */
    public function persistNeighbors(Device $source, array $neighbors, CarbonInterface $seenAt): int
    {
        $localInterfaces = $source->interfaces()->get()->keyBy('interface_name');
        $persisted = 0;

        foreach ($neighbors as $neighbor) {
            $remote = $this->findRemoteDevice($neighbor);
            $localInterface = $localInterfaces->get($neighbor['interface'] ?? null);
            $remoteKey = $this->remoteKey($neighbor, $remote);
            $canonical = $this->canonicalEndpoint($source->id, $localInterface?->id, $remote?->id, null, $remoteKey);

            $link = $remote
                ? $this->findExistingPair($source->id, $remote->id)
                : null;
            $link ??= PhysicalLink::firstOrNew(['dedupe_key' => $canonical['dedupe_key']]);

            $deviceAId = $canonical['device_a_id'];
            $deviceBId = $canonical['device_b_id'];
            $interfaceAId = $link->interface_a_id;
            $interfaceBId = $link->interface_b_id;

            if ($source->id === $deviceAId) {
                $interfaceAId ??= $localInterface?->id;
            } else {
                $interfaceBId ??= $localInterface?->id;
            }

            $link->fill([
                'device_a_id' => $deviceAId,
                'interface_a_id' => $interfaceAId,
                'device_b_id' => $deviceBId,
                'interface_b_id' => $interfaceBId,
                'relationship_type' => 'physical',
                'discovery_source' => 'routeros_neighbor',
                'verification_status' => 'verified',
                'dedupe_key' => $this->endpointKey($deviceAId, $interfaceAId, $deviceBId, $interfaceBId, $canonical['dedupe_key']),
                'first_seen_at' => $link->exists ? $link->first_seen_at : $seenAt,
                'last_seen_at' => $seenAt,
                'metadata' => [
                    'remote_ip' => $neighbor['address'] ?? null,
                    'remote_mac' => $neighbor['mac'] ?? null,
                    'remote_identity' => $neighbor['identity'] ?? null,
                    'remote_platform' => $neighbor['platform'] ?? null,
                    'remote_board' => $neighbor['board'] ?? null,
                    'remote_version' => $neighbor['version'] ?? null,
                    'local_interface_name' => $neighbor['interface'] ?? null,
                    'remote_interface_known' => false,
                ],
            ]);
            $link->save();
            $persisted++;
        }

        return $persisted;
    }

    /** @param array<int, int> $deviceIds */
    public function forDevices(array $deviceIds): Collection
    {
        return PhysicalLink::query()
            ->with([
                'deviceA:id,name,ip_address',
                'interfaceA:id,device_id,interface_name,interface_status',
                'deviceB:id,name,ip_address',
                'interfaceB:id,device_id,interface_name,interface_status',
            ])
            ->where(function ($query) use ($deviceIds) {
                $query->whereIn('device_a_id', $deviceIds)->orWhereIn('device_b_id', $deviceIds);
            })
            ->get();
    }

    private function findRemoteDevice(array $neighbor): ?Device
    {
        $ip = $neighbor['address'] ?? null;
        $mac = $neighbor['mac'] ?? null;

        if (! $ip && ! $mac) {
            return null;
        }

        return Device::query()
            ->when($ip || $mac, function ($query) use ($ip, $mac) {
                $query->where(function ($nested) use ($ip, $mac) {
                    if ($ip) $nested->where('ip_address', $ip);
                    if ($mac) $nested->orWhere('mac_address', $mac);
                });
            })
            ->first();
    }

    private function remoteKey(array $neighbor, ?Device $remote): string
    {
        return 'device:' . ($remote?->id ?? 'unknown')
            . '|ip:' . ($neighbor['address'] ?? '')
            . '|mac:' . ($neighbor['mac'] ?? '')
            . '|identity:' . ($neighbor['identity'] ?? '');
    }

    private function findExistingPair(int $sourceDeviceId, int $remoteDeviceId): ?PhysicalLink
    {
        return PhysicalLink::query()
            ->where(function ($query) use ($sourceDeviceId, $remoteDeviceId) {
                $query->where('device_a_id', $sourceDeviceId)->where('device_b_id', $remoteDeviceId);
            })
            ->orWhere(function ($query) use ($sourceDeviceId, $remoteDeviceId) {
                $query->where('device_a_id', $remoteDeviceId)->where('device_b_id', $sourceDeviceId);
            })
            ->first();
    }

    private function endpointKey(int $deviceAId, ?int $interfaceAId, ?int $deviceBId, ?int $interfaceBId, string $fallback): string
    {
        if ($deviceBId === null) return $fallback;

        return "device:{$deviceAId}|interface:" . ($interfaceAId ?? '') . '|device:' . $deviceBId . '|interface:' . ($interfaceBId ?? '');
    }

    /**
     * Canonicalize known device endpoints so reverse discovery cannot duplicate
     * an undirected physical relationship. Unknown remote endpoints remain
     * anchored to the observing device and use their remote identity in the key.
     *
     * @return array{device_a_id:int,interface_a_id:?int,device_b_id:?int,dedupe_key:string}
     */
    private function canonicalEndpoint(int $sourceDeviceId, ?int $sourceInterfaceId, ?int $remoteDeviceId, ?int $remoteInterfaceId, string $remoteKey): array
    {
        if ($remoteDeviceId !== null && $remoteDeviceId < $sourceDeviceId) {
            return [
                'device_a_id' => $remoteDeviceId,
                'interface_a_id' => $remoteInterfaceId,
                'device_b_id' => $sourceDeviceId,
                'dedupe_key' => "device:{$remoteDeviceId}|interface:" . ($remoteInterfaceId ?? '') . '|device:' . $sourceDeviceId . '|interface:' . ($sourceInterfaceId ?? ''),
            ];
        }

        if ($remoteDeviceId !== null) {
            return [
                'device_a_id' => $sourceDeviceId,
                'interface_a_id' => $sourceInterfaceId,
                'device_b_id' => $remoteDeviceId,
                'dedupe_key' => "device:{$sourceDeviceId}|interface:" . ($sourceInterfaceId ?? '') . '|device:' . $remoteDeviceId . '|interface:' . ($remoteInterfaceId ?? ''),
            ];
        }

        return [
            'device_a_id' => $sourceDeviceId,
            'interface_a_id' => $sourceInterfaceId,
            'device_b_id' => null,
            'dedupe_key' => "device:{$sourceDeviceId}|interface:" . ($sourceInterfaceId ?? '') . '|remote:' . sha1($remoteKey),
        ];
    }
}
