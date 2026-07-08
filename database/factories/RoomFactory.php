<?php

namespace Database\Factories;

use App\Models\Room;
use App\Models\Floor;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoomFactory extends Factory
{
    protected $model = Room::class;

    public function definition(): array
    {
        return [
            'floor_id' => Floor::factory(),
            'name' => 'Ruang ' . fake()->word(),
            'code' => strtoupper(fake()->unique()->bothify('??-###')),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
