<?php

namespace App\Repositories;

use App\Models\Rack;
use App\Interface\RackRepositoryInterface;

class RackRepository implements RackRepositoryInterface
{
    public function paginate(int $perPage = 15, array $filters = [])
    {
        $query = Rack::query()->with(['room.floor.building']);

        if (!empty($filters['room_id'])) {
            $query->where('room_id', $filters['room_id']);
        }

        if (!empty($filters['floor_id'])) {
            $query->whereHas('room', function($rq) use ($filters) {
                $rq->where('floor_id', $filters['floor_id']);
            });
        }

        if (!empty($filters['building_id'])) {
            $query->whereHas('room.floor', function($fq) use ($filters) {
                $fq->where('building_id', $filters['building_id']);
            });
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('room', function($rq) use ($search) {
                      $rq->where('name', 'like', "%{$search}%")
                        ->orWhereHas('floor', function($fq) use ($search) {
                            $fq->where('name', 'like', "%{$search}%");
                        });
                  });
            });
        }

        return $query->paginate($perPage);
    }

    public function all()
    {
        return Rack::with(['room.floor.building'])->get();
    }

    public function find(int $id)
    {
        return Rack::with(['room.floor.building'])->findOrFail($id);
    }

    public function create(array $data)
    {
        return Rack::create($data)->load(['room.floor.building']);
    }

    public function update(int $id, array $data)
    {
        $rack = Rack::findOrFail($id);
        $rack->update($data);
        return $rack->load(['room.floor.building']);
    }

    public function delete(int $id)
    {
        $rack = Rack::findOrFail($id);
        return $rack->delete();
    }
}
