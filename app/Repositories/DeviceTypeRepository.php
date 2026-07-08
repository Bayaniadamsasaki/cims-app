<?php

namespace App\Repositories;

use App\Models\DeviceType;
use App\Interface\DeviceTypeRepositoryInterface;

class DeviceTypeRepository implements DeviceTypeRepositoryInterface
{
    public function paginate(int $perPage = 15, array $filters = [])
    {
        $query = DeviceType::query();

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    public function all()
    {
        return DeviceType::all();
    }

    public function find(int $id)
    {
        return DeviceType::findOrFail($id);
    }

    public function create(array $data)
    {
        return DeviceType::create($data);
    }

    public function update(int $id, array $data)
    {
        $deviceType = DeviceType::findOrFail($id);
        $deviceType->update($data);
        return $deviceType;
    }

    public function delete(int $id)
    {
        $deviceType = DeviceType::findOrFail($id);
        return $deviceType->delete();
    }
}
