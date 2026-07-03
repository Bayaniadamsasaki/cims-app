<?php

namespace App\Repositories;

use App\Models\Building;
use App\Interface\BuildingRepositoryInterface;

class BuildingRepository implements BuildingRepositoryInterface
{
    public function paginate(int $perPage = 15, array $filters = [])
    {
        $query = Building::query();

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    public function all()
    {
        return Building::all();
    }

    public function find(int $id)
    {
        return Building::findOrFail($id);
    }

    public function create(array $data)
    {
        return Building::create($data);
    }

    public function update(int $id, array $data)
    {
        $building = Building::findOrFail($id);
        $building->update($data);
        return $building;
    }

    public function delete(int $id)
    {
        $building = Building::findOrFail($id);
        return $building->delete();
    }
}
