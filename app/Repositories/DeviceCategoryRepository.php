<?php

namespace App\Repositories;

use App\Models\DeviceCategory;
use App\Interface\DeviceCategoryRepositoryInterface;

class DeviceCategoryRepository implements DeviceCategoryRepositoryInterface
{
    public function paginate(int $perPage = 15, array $filters = [])
    {
        $query = DeviceCategory::query();

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    public function all()
    {
        return DeviceCategory::all();
    }

    public function find(int $id)
    {
        return DeviceCategory::findOrFail($id);
    }

    public function create(array $data)
    {
        return DeviceCategory::create($data);
    }

    public function update(int $id, array $data)
    {
        $category = DeviceCategory::findOrFail($id);
        $category->update($data);
        return $category;
    }

    public function delete(int $id)
    {
        $category = DeviceCategory::findOrFail($id);
        return $category->delete();
    }
}
