<?php

namespace Database\Factories;

use App\Models\DeviceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeviceCategoryFactory extends Factory
{
    protected $model = DeviceCategory::class;

    public function definition(): array
    {
        $categories = ['Router', 'Switch', 'Access Point', 'Server', 'Firewall', 'UPS', 'NAS', 'Load Balancer'];

        return [
            'name' => fake()->unique()->randomElement($categories),
            'description' => fake()->sentence(),
        ];
    }
}
