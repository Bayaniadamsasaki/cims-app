<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StoreDeviceTypeRequest;
use App\Http\Requests\Master\UpdateDeviceTypeRequest;
use App\Http\Resources\DeviceTypeResource;
use App\Interface\DeviceTypeRepositoryInterface;
use Illuminate\Http\Request;

class DeviceTypeController extends Controller
{
    protected $deviceTypeRepository;

    public function __construct(DeviceTypeRepositoryInterface $deviceTypeRepository)
    {
        $this->deviceTypeRepository = $deviceTypeRepository;
    }

    public function index(Request $request)
    {
        $perPage = $request->query('per_page', 15);
        $filters = $request->only(['search']);

        $deviceTypes = $this->deviceTypeRepository->paginate($perPage, $filters);
        return DeviceTypeResource::collection($deviceTypes);
    }

    public function store(StoreDeviceTypeRequest $request)
    {
        $deviceType = $this->deviceTypeRepository->create($request->validated());
        return new DeviceTypeResource($deviceType);
    }

    public function show(int $id)
    {
        $deviceType = $this->deviceTypeRepository->find($id);
        return new DeviceTypeResource($deviceType);
    }

    public function update(UpdateDeviceTypeRequest $request, int $id)
    {
        $deviceType = $this->deviceTypeRepository->update($id, $request->validated());
        return new DeviceTypeResource($deviceType);
    }

    public function destroy(int $id)
    {
        $this->deviceTypeRepository->delete($id);

        return response()->json([
            'message' => 'Device type deleted successfully'
        ]);
    }
}
