<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceInterfaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'interface_name' => $this->interface_name,
            'ip_address' => $this->ip_address,
            'subnet' => $this->subnet,
            'gateway' => $this->gateway,
            'mac_address' => $this->mac_address,
            'interface_type' => $this->interface_type,
            'interface_status' => $this->interface_status,
            'speed' => $this->speed,
            'mtu' => $this->mtu,
            'description' => $this->description,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
