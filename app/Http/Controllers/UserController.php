<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Interface\UserRepositoryInterface;
use Illuminate\Http\Request;

class UserController extends Controller
{
    protected $userRepository;

    public function __construct(UserRepositoryInterface $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function index(Request $request)
    {
        $perPage = $request->query('per_page', 15);
        $filters = $request->only(['search', 'role']);

        $users = $this->userRepository->paginate($perPage, $filters);
        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request)
    {
        $user = $this->userRepository->create($request->validated());
        return new UserResource($user);
    }

    public function show(int $id)
    {
        $user = $this->userRepository->find($id);
        return new UserResource($user);
    }

    public function update(UpdateUserRequest $request, int $id)
    {
        $user = $this->userRepository->update($id, $request->validated());
        return new UserResource($user);
    }

    public function destroy(int $id)
    {
        $this->userRepository->delete($id);
        return response()->json([
            'message' => 'User deleted successfully'
        ]);
    }
}
