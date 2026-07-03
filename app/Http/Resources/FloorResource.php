<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FloorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'name' => $this->name,
            'level' => $this->level,
            'description' => $this->description,
            'building' => new BuildingResource($this->whenLoaded('building')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
