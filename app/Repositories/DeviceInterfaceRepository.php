<?php

namespace App\Repositories;

use App\Models\DeviceInterface;
use App\Interface\DeviceInterfaceRepositoryInterface;

class DeviceInterfaceRepository implements DeviceInterfaceRepositoryInterface
{
    public function paginateByDevice(int $deviceId, int $perPage = 15, array $filters = [])
    {
        $query = DeviceInterface::where('device_id', $deviceId);

        if (!empty($filters['interface_type'])) {
            $query->where('interface_type', $filters['interface_type']);
        }

        if (!empty($filters['interface_status'])) {
            $query->where('interface_status', $filters['interface_status']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('interface_name', 'like', "%{$search}%")
                  ->orWhere('ip_address', 'like', "%{$search}%")
                  ->orWhere('mac_address', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    public function findByDevice(int $deviceId, int $id)
    {
        return DeviceInterface::where('device_id', $deviceId)->findOrFail($id);
    }

    public function create(array $data)
    {
        return DeviceInterface::create($data);
    }

    public function update(int $id, array $data)
    {
        $interface = DeviceInterface::findOrFail($id);
        $interface->update($data);
        return $interface;
    }

    public function delete(int $id)
    {
        $interface = DeviceInterface::findOrFail($id);
        return $interface->delete();
    }
}
