<?php

namespace App\Repositories;

use App\Models\Floor;
use App\Interface\FloorRepositoryInterface;

class FloorRepository implements FloorRepositoryInterface
{
    public function paginate(int $perPage = 15, array $filters = [])
    {
        $query = Floor::query()->with('building');

        if (!empty($filters['building_id'])) {
            $query->where('building_id', $filters['building_id']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('building', function($bq) use ($search) {
                      $bq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        return $query->paginate($perPage);
    }

    public function all()
    {
        return Floor::with('building')->get();
    }

    public function find(int $id)
    {
        return Floor::with('building')->findOrFail($id);
    }

    public function create(array $data)
    {
        return Floor::create($data)->load('building');
    }

    public function update(int $id, array $data)
    {
        $floor = Floor::findOrFail($id);
        $floor->update($data);
        return $floor->load('building');
    }

    public function delete(int $id)
    {
        $floor = Floor::findOrFail($id);
        return $floor->delete();
    }
}
