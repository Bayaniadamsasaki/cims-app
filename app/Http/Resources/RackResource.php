<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'room_id' => $this->room_id,
            'name' => $this->name,
            'capacity' => $this->capacity,
            'code' => $this->code,
            'description' => $this->description,
            'room' => new RoomResource($this->whenLoaded('room')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
