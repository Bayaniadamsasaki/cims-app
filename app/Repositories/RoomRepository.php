<?php

namespace App\Repositories;

use App\Models\Room;
use App\Interface\RoomRepositoryInterface;

class RoomRepository implements RoomRepositoryInterface
{
    public function paginate(int $perPage = 15, array $filters = [])
    {
        $query = Room::query()->with(['floor.building']);

        if (!empty($filters['floor_id'])) {
            $query->where('floor_id', $filters['floor_id']);
        }

        if (!empty($filters['building_id'])) {
            $query->whereHas('floor', function($fq) use ($filters) {
                $fq->where('building_id', $filters['building_id']);
            });
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('floor', function($fq) use ($search) {
                      $fq->where('name', 'like', "%{$search}%")
                        ->orWhereHas('building', function($bq) use ($search) {
                            $bq->where('name', 'like', "%{$search}%");
                        });
                  });
            });
        }

        return $query->paginate($perPage);
    }

    public function all()
    {
        return Room::with(['floor.building'])->get();
    }

    public function find(int $id)
    {
        return Room::with(['floor.building'])->findOrFail($id);
    }

    public function create(array $data)
    {
        return Room::create($data)->load(['floor.building']);
    }

    public function update(int $id, array $data)
    {
        $room = Room::findOrFail($id);
        $room->update($data);
        return $room->load(['floor.building']);
    }

    public function delete(int $id)
    {
        $room = Room::findOrFail($id);
        return $room->delete();
    }
}
