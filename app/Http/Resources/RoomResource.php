<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'floor_id' => $this->floor_id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'floor' => new FloorResource($this->whenLoaded('floor')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
