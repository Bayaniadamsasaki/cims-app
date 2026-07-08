<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StoreOperatingSystemRequest;
use App\Http\Requests\Master\UpdateOperatingSystemRequest;
use App\Http\Resources\OperatingSystemResource;
use App\Interface\OperatingSystemRepositoryInterface;
use Illuminate\Http\Request;

class OperatingSystemController extends Controller
{
    protected $operatingSystemRepository;

    public function __construct(OperatingSystemRepositoryInterface $operatingSystemRepository)
    {
        $this->operatingSystemRepository = $operatingSystemRepository;
    }

    public function index(Request $request)
    {
        $perPage = $request->query('per_page', 15);
        $filters = $request->only(['search']);

        $operatingSystems = $this->operatingSystemRepository->paginate($perPage, $filters);
        return OperatingSystemResource::collection($operatingSystems);
    }

    public function store(StoreOperatingSystemRequest $request)
    {
        $os = $this->operatingSystemRepository->create($request->validated());
        return new OperatingSystemResource($os);
    }

    public function show(int $id)
    {
        $os = $this->operatingSystemRepository->find($id);
        return new OperatingSystemResource($os);
    }

    public function update(UpdateOperatingSystemRequest $request, int $id)
    {
        $os = $this->operatingSystemRepository->update($id, $request->validated());
        return new OperatingSystemResource($os);
    }

    public function destroy(int $id)
    {
        $this->operatingSystemRepository->delete($id);

        return response()->json([
            'message' => 'Operating system deleted successfully'
        ]);
    }
}
