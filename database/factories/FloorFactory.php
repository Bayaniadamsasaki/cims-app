<?php

namespace Database\Factories;

use App\Models\Floor;
use App\Models\Building;
use Illuminate\Database\Eloquent\Factories\Factory;

class FloorFactory extends Factory
{
    protected $model = Floor::class;

    public function definition(): array
    {
        return [
            'building_id' => Building::factory(),
            'name' => 'Lantai ' . fake()->numberBetween(1, 10),
            'level' => fake()->numberBetween(1, 10),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
