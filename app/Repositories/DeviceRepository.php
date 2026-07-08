<?php

namespace App\Repositories;

use App\Models\Device;
use App\Interface\DeviceRepositoryInterface;

class DeviceRepository implements DeviceRepositoryInterface
{
    protected array $defaultRelations = [
        'vendor', 'category', 'operatingSystem', 'deviceType',
        'building', 'floor', 'room', 'rack',
    ];

    public function paginate(int $perPage = 15, array $filters = [])
    {
        $query = Device::query()->with($this->defaultRelations)
            ->withCount('deviceInterfaces');

        if (!empty($filters['vendor_id'])) {
            $query->where('vendor_id', $filters['vendor_id']);
        }

        if (!empty($filters['device_category_id'])) {
            $query->where('device_category_id', $filters['device_category_id']);
        }

        if (!empty($filters['operating_system_id'])) {
            $query->where('operating_system_id', $filters['operating_system_id']);
        }

        if (!empty($filters['device_type_id'])) {
            $query->where('device_type_id', $filters['device_type_id']);
        }

        if (!empty($filters['building_id'])) {
            $query->where('building_id', $filters['building_id']);
        }

        if (!empty($filters['floor_id'])) {
            $query->where('floor_id', $filters['floor_id']);
        }

        if (!empty($filters['room_id'])) {
            $query->where('room_id', $filters['room_id']);
        }

        if (!empty($filters['rack_id'])) {
            $query->where('rack_id', $filters['rack_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('hostname', 'like', "%{$search}%")
                  ->orWhere('ip_address', 'like', "%{$search}%")
                  ->orWhere('mac_address', 'like', "%{$search}%")
                  ->orWhere('serial_number', 'like', "%{$search}%")
                  ->orWhere('model', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    public function find(int $id)
    {
        return Device::with(array_merge($this->defaultRelations, ['deviceInterfaces']))
            ->findOrFail($id);
    }

    public function create(array $data)
    {
        return Device::create($data)->load($this->defaultRelations);
    }

    public function update(int $id, array $data)
    {
        $device = Device::findOrFail($id);
        $device->update($data);
        return $device->load($this->defaultRelations);
    }

    public function delete(int $id)
    {
        $device = Device::findOrFail($id);
        return $device->delete();
    }
}
