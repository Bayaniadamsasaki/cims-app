<?php

namespace App\Repositories;

use App\Models\Vendor;
use App\Interface\VendorRepositoryInterface;

class VendorRepository implements VendorRepositoryInterface
{
    public function paginate(int $perPage = 15, array $filters = [])
    {
        $query = Vendor::query();

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('contact_person', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('address', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    public function all()
    {
        return Vendor::all();
    }

    public function find(int $id)
    {
        return Vendor::findOrFail($id);
    }

    public function create(array $data)
    {
        return Vendor::create($data);
    }

    public function update(int $id, array $data)
    {
        $vendor = Vendor::findOrFail($id);
        $vendor->update($data);
        return $vendor;
    }

    public function delete(int $id)
    {
        $vendor = Vendor::findOrFail($id);
        return $vendor->delete();
    }
}
