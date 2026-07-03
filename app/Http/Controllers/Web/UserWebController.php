<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Interface\UserRepositoryInterface;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Inertia\Inertia;

class UserWebController extends Controller
{
    protected $userRepo;

    public function __construct(UserRepositoryInterface $userRepo)
    {
        $this->userRepo = $userRepo;
    }

    public function index(Request $request)
    {
        $filters = $request->only(['search', 'role']);
        $users = $this->userRepo->paginate(100, $filters);
        
        // Eager load roles for frontend
        $usersCollection = collect($users->items())->map(function ($user) {
            $userModel = \App\Models\User::find($user->id);
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $userModel ? $userModel->getRoleNames() : [],
            ];
        });

        $roles = Role::all()->pluck('name');

        return Inertia::render('Users/Index', [
            'users' => $usersCollection,
            'roles' => $roles,
            'filters' => $filters,
        ]);
    }

    public function store(StoreUserRequest $request)
    {
        $this->userRepo->create($request->validated());
        return redirect()->route('users.index')->with('success', 'User created successfully.');
    }

    public function update(UpdateUserRequest $request, int $id)
    {
        $this->userRepo->update($id, $request->validated());
        return redirect()->route('users.index')->with('success', 'User updated successfully.');
    }

    public function destroy(int $id)
    {
        $this->userRepo->delete($id);
        return redirect()->route('users.index')->with('success', 'User deleted successfully.');
    }
}
