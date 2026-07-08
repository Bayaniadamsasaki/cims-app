<?php

namespace App\Repositories;

use App\Models\OperatingSystem;
use App\Interface\OperatingSystemRepositoryInterface;

class OperatingSystemRepository implements OperatingSystemRepositoryInterface
{
    public function paginate(int $perPage = 15, array $filters = [])
    {
        $query = OperatingSystem::query();

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('vendor', 'like', "%{$search}%")
                  ->orWhere('version', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    public function all()
    {
        return OperatingSystem::all();
    }

    public function find(int $id)
    {
        return OperatingSystem::findOrFail($id);
    }

    public function create(array $data)
    {
        return OperatingSystem::create($data);
    }

    public function update(int $id, array $data)
    {
        $os = OperatingSystem::findOrFail($id);
        $os->update($data);
        return $os;
    }

    public function delete(int $id)
    {
        $os = OperatingSystem::findOrFail($id);
        return $os->delete();
    }
}
