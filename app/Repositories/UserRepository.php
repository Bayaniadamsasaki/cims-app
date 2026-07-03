<?php

namespace App\Repositories;

use App\Models\User;
use App\Interface\UserRepositoryInterface;

class UserRepository implements UserRepositoryInterface
{
    public function paginate(int $perPage = 15, array $filters = [])
    {
        $query = User::query();

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['role'])) {
            $query->role($filters['role']);
        }

        return $query->with('roles')->paginate($perPage);
    }

    public function find(int $id)
    {
        return User::with('roles')->findOrFail($id);
    }

    public function create(array $data)
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => bcrypt($data['password']),
        ]);

        if (!empty($data['roles'])) {
            $user->syncRoles($data['roles']);
        }

        return $user->load('roles');
    }

    public function update(int $id, array $data)
    {
        $user = User::findOrFail($id);

        $updateData = [
            'name' => $data['name'] ?? $user->name,
            'email' => $data['email'] ?? $user->email,
        ];

        if (!empty($data['password'])) {
            $updateData['password'] = bcrypt($data['password']);
        }

        $user->update($updateData);

        if (isset($data['roles'])) {
            $user->syncRoles($data['roles']);
        }

        return $user->load('roles');
    }

    public function delete(int $id)
    {
        $user = User::findOrFail($id);
        return $user->delete();
    }
}
