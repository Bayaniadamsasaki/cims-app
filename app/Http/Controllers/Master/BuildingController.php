<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StoreBuildingRequest;
use App\Http\Requests\Master\UpdateBuildingRequest;
use App\Http\Resources\BuildingResource;
use App\Interface\BuildingRepositoryInterface;
use Illuminate\Http\Request;

class BuildingController extends Controller
{
    protected $buildingRepository;

    public function __construct(BuildingRepositoryInterface $buildingRepository)
    {
        $this->buildingRepository = $buildingRepository;
    }

    public function index(Request $request)
    {
        if ($request->boolean('all')) {
            return BuildingResource::collection($this->buildingRepository->all());
        }

        $perPage = $request->query('per_page', 15);
        $filters = $request->only(['search']);

        $buildings = $this->buildingRepository->paginate($perPage, $filters);
        return BuildingResource::collection($buildings);
    }

    public function store(StoreBuildingRequest $request)
    {
        $building = $this->buildingRepository->create($request->validated());
        return new BuildingResource($building);
    }

    public function show(int $id)
    {
        $building = $this->buildingRepository->find($id);
        return new BuildingResource($building);
    }

    public function update(UpdateBuildingRequest $request, int $id)
    {
        $building = $this->buildingRepository->update($id, $request->validated());
        return new BuildingResource($building);
    }

    public function destroy(int $id)
    {
        $this->buildingRepository->delete($id);
        return response()->json([
            'message' => 'Building deleted successfully'
        ]);
    }
}
