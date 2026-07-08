<?php

namespace App\Http\Controllers;

use App\Http\Requests\Device\StoreDeviceInterfaceRequest;
use App\Http\Requests\Device\UpdateDeviceInterfaceRequest;
use App\Http\Resources\DeviceInterfaceResource;
use App\Interface\DeviceInterfaceRepositoryInterface;
use Illuminate\Http\Request;

class DeviceInterfaceController extends Controller
{
    protected $deviceInterfaceRepository;

    public function __construct(DeviceInterfaceRepositoryInterface $deviceInterfaceRepository)
    {
        $this->deviceInterfaceRepository = $deviceInterfaceRepository;
    }

    /**
     * List all interfaces for a specific device.
     */
    public function index(Request $request, int $deviceId)
    {
        $perPage = $request->query('per_page', 15);
        $filters = $request->only(['interface_type', 'interface_status', 'search']);

        $interfaces = $this->deviceInterfaceRepository->paginateByDevice($deviceId, $perPage, $filters);
        return DeviceInterfaceResource::collection($interfaces);
    }

    /**
     * Store a new interface for a device.
     */
    public function store(StoreDeviceInterfaceRequest $request, int $deviceId)
    {
        $data = $request->validated();
        $data['device_id'] = $deviceId;

        $interface = $this->deviceInterfaceRepository->create($data);
        return new DeviceInterfaceResource($interface);
    }

    /**
     * Show a specific interface of a device.
     */
    public function show(int $deviceId, int $id)
    {
        $interface = $this->deviceInterfaceRepository->findByDevice($deviceId, $id);
        return new DeviceInterfaceResource($interface);
    }

    /**
     * Update a specific interface.
     */
    public function update(UpdateDeviceInterfaceRequest $request, int $deviceId, int $id)
    {
        $interface = $this->deviceInterfaceRepository->findByDevice($deviceId, $id);
        $updated = $this->deviceInterfaceRepository->update($interface->id, $request->validated());
        return new DeviceInterfaceResource($updated);
    }

    /**
     * Delete a specific interface.
     */
    public function destroy(int $deviceId, int $id)
    {
        $interface = $this->deviceInterfaceRepository->findByDevice($deviceId, $id);
        $this->deviceInterfaceRepository->delete($interface->id);

        return response()->json([
            'message' => 'Device interface deleted successfully'
        ]);
    }
}
