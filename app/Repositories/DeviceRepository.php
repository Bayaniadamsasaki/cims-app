<?php

namespace App\Repositories;

use App\Models\Device;
use App\Interface\DeviceRepositoryInterface;

class DeviceRepository implements DeviceRepositoryInterface
{
    public function paginate(int $perPage = 15, array $filters = [])
    {
        $query = Device::query()->with([
            'vendor', 'category', 'building', 'floor', 'room', 'rack'
        ]);

        if (!empty($filters['vendor_id'])) {
            $query->where('vendor_id', $filters['vendor_id']);
        }

        if (!empty($filters['device_category_id'])) {
            $query->where('device_category_id', $filters['device_category_id']);
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
        return Device::with([
            'vendor', 'category', 'building', 'floor', 'room', 'rack'
        ])->findOrFail($id);
    }

    public function create(array $data)
    {
        return Device::create($data)->load([
            'vendor', 'category', 'building', 'floor', 'room', 'rack'
        ]);
    }

    public function update(int $id, array $data)
    {
        $device = Device::findOrFail($id);
        $device->update($data);
        return $device->load([
            'vendor', 'category', 'building', 'floor', 'room', 'rack'
        ]);
    }

    public function delete(int $id)
    {
        $device = Device::findOrFail($id);
        return $device->delete();
    }
}
