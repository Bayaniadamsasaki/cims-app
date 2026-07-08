<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'hostname' => $this->hostname,
            'ip_address' => $this->ip_address,
            'mac_address' => $this->mac_address,
            
            'vendor_id' => $this->vendor_id,
            'vendor' => new VendorResource($this->whenLoaded('vendor')),
            
            'device_category_id' => $this->device_category_id,
            'category' => new DeviceCategoryResource($this->whenLoaded('category')),

            'operating_system_id' => $this->operating_system_id,
            'operating_system' => new OperatingSystemResource($this->whenLoaded('operatingSystem')),

            'device_type_id' => $this->device_type_id,
            'device_type' => new DeviceTypeResource($this->whenLoaded('deviceType')),
            
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'firmware' => $this->firmware,
            'purchase_date' => $this->purchase_date,
            'warranty' => $this->warranty,
            'username' => $this->username,
            
            'building_id' => $this->building_id,
            'building' => new BuildingResource($this->whenLoaded('building')),
            
            'floor_id' => $this->floor_id,
            'floor' => new FloorResource($this->whenLoaded('floor')),
            
            'room_id' => $this->room_id,
            'room' => new RoomResource($this->whenLoaded('room')),
            
            'rack_id' => $this->rack_id,
            'rack' => new RackResource($this->whenLoaded('rack')),
            
            'status' => $this->status,
            'notes' => $this->notes,
            'image_url' => $this->image_path ? asset('storage/' . $this->image_path) : null,

            'interfaces' => DeviceInterfaceResource::collection($this->whenLoaded('deviceInterfaces')),
            'interfaces_count' => $this->whenCounted('deviceInterfaces'),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
