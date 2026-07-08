<?php

namespace App\Http\Controllers;

use App\Http\Requests\Device\StoreDeviceRequest;
use App\Http\Requests\Device\UpdateDeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Interface\DeviceRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DeviceController extends Controller
{
    protected $deviceRepository;

    public function __construct(DeviceRepositoryInterface $deviceRepository)
    {
        $this->deviceRepository = $deviceRepository;
    }

    public function index(Request $request)
    {
        $perPage = $request->query('per_page', 15);
        $filters = $request->only([
            'vendor_id', 'device_category_id', 'operating_system_id', 'device_type_id',
            'building_id', 'floor_id', 'room_id', 'rack_id', 'status', 'search'
        ]);

        $devices = $this->deviceRepository->paginate($perPage, $filters);
        return DeviceResource::collection($devices);
    }

    public function store(StoreDeviceRequest $request)
    {
        $data = $request->validated();

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('devices', 'public');
            $data['image_path'] = $path;
        }

        $device = $this->deviceRepository->create($data);
        return new DeviceResource($device);
    }

    public function show(int $id)
    {
        $device = $this->deviceRepository->find($id);
        return new DeviceResource($device);
    }

    public function update(UpdateDeviceRequest $request, int $id)
    {
        $data = $request->validated();
        $device = $this->deviceRepository->find($id);

        if ($request->hasFile('image')) {
            if ($device->image_path) {
                Storage::disk('public')->delete($device->image_path);
            }
            $path = $request->file('image')->store('devices', 'public');
            $data['image_path'] = $path;
        }

        $updatedDevice = $this->deviceRepository->update($id, $data);
        return new DeviceResource($updatedDevice);
    }

    public function destroy(int $id)
    {
        $device = $this->deviceRepository->find($id);

        if ($device->image_path) {
            Storage::disk('public')->delete($device->image_path);
        }

        $this->deviceRepository->delete($id);

        return response()->json([
            'message' => 'Device deleted successfully'
        ]);
    }
}
