<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StoreRackRequest;
use App\Http\Requests\Master\UpdateRackRequest;
use App\Http\Resources\RackResource;
use App\Interface\RackRepositoryInterface;
use Illuminate\Http\Request;

class RackController extends Controller
{
    protected $rackRepository;

    public function __construct(RackRepositoryInterface $rackRepository)
    {
        $this->rackRepository = $rackRepository;
    }

    public function index(Request $request)
    {
        if ($request->boolean('all')) {
            return RackResource::collection($this->rackRepository->all());
        }

        $perPage = $request->query('per_page', 15);
        $filters = $request->only(['room_id', 'floor_id', 'building_id', 'search']);

        $racks = $this->rackRepository->paginate($perPage, $filters);
        return RackResource::collection($racks);
    }

    public function store(StoreRackRequest $request)
    {
        $rack = $this->rackRepository->create($request->validated());
        return new RackResource($rack);
    }

    public function show(int $id)
    {
        $rack = $this->rackRepository->find($id);
        return new RackResource($rack);
    }

    public function update(UpdateRackRequest $request, int $id)
    {
        $rack = $this->rackRepository->update($id, $request->validated());
        return new RackResource($rack);
    }

    public function destroy(int $id)
    {
        $this->rackRepository->delete($id);
        return response()->json([
            'message' => 'Rack deleted successfully'
        ]);
    }
}
