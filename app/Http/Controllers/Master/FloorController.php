<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StoreFloorRequest;
use App\Http\Requests\Master\UpdateFloorRequest;
use App\Http\Resources\FloorResource;
use App\Interface\FloorRepositoryInterface;
use Illuminate\Http\Request;

class FloorController extends Controller
{
    protected $floorRepository;

    public function __construct(FloorRepositoryInterface $floorRepository)
    {
        $this->floorRepository = $floorRepository;
    }

    public function index(Request $request)
    {
        if ($request->boolean('all')) {
            return FloorResource::collection($this->floorRepository->all());
        }

        $perPage = $request->query('per_page', 15);
        $filters = $request->only(['building_id', 'search']);

        $floors = $this->floorRepository->paginate($perPage, $filters);
        return FloorResource::collection($floors);
    }

    public function store(StoreFloorRequest $request)
    {
        $floor = $this->floorRepository->create($request->validated());
        return new FloorResource($floor);
    }

    public function show(int $id)
    {
        $floor = $this->floorRepository->find($id);
        return new FloorResource($floor);
    }

    public function update(UpdateFloorRequest $request, int $id)
    {
        $floor = $this->floorRepository->update($id, $request->validated());
        return new FloorResource($floor);
    }

    public function destroy(int $id)
    {
        $this->floorRepository->delete($id);
        return response()->json([
            'message' => 'Floor deleted successfully'
        ]);
    }
}
