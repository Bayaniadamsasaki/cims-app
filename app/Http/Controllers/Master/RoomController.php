<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StoreRoomRequest;
use App\Http\Requests\Master\UpdateRoomRequest;
use App\Http\Resources\RoomResource;
use App\Interface\RoomRepositoryInterface;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    protected $roomRepository;

    public function __construct(RoomRepositoryInterface $roomRepository)
    {
        $this->roomRepository = $roomRepository;
    }

    public function index(Request $request)
    {
        if ($request->boolean('all')) {
            return RoomResource::collection($this->roomRepository->all());
        }

        $perPage = $request->query('per_page', 15);
        $filters = $request->only(['floor_id', 'building_id', 'search']);

        $rooms = $this->roomRepository->paginate($perPage, $filters);
        return RoomResource::collection($rooms);
    }

    public function store(StoreRoomRequest $request)
    {
        $room = $this->roomRepository->create($request->validated());
        return new RoomResource($room);
    }

    public function show(int $id)
    {
        $room = $this->roomRepository->find($id);
        return new RoomResource($room);
    }

    public function update(UpdateRoomRequest $request, int $id)
    {
        $room = $this->roomRepository->update($id, $request->validated());
        return new RoomResource($room);
    }

    public function destroy(int $id)
    {
        $this->roomRepository->delete($id);
        return response()->json([
            'message' => 'Room deleted successfully'
        ]);
    }
}
