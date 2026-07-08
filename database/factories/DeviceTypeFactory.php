<?php

namespace Database\Factories;

use App\Models\DeviceType;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeviceTypeFactory extends Factory
{
    protected $model = DeviceType::class;

    public function definition(): array
    {
        $types = [
            ['name' => 'Core Router', 'description' => 'Perangkat routing utama backbone jaringan.'],
            ['name' => 'Distribution Switch', 'description' => 'Switch distribusi untuk segmentasi jaringan.'],
            ['name' => 'Access Switch', 'description' => 'Switch akses untuk endpoint pengguna.'],
            ['name' => 'Wireless Controller', 'description' => 'Controller untuk manajemen access point.'],
            ['name' => 'Indoor AP', 'description' => 'Access point untuk penggunaan dalam ruangan.'],
            ['name' => 'Outdoor AP', 'description' => 'Access point untuk penggunaan luar ruangan.'],
            ['name' => 'Rack Server', 'description' => 'Server fisik berbentuk rack-mount.'],
            ['name' => 'Tower Server', 'description' => 'Server fisik berbentuk tower.'],
        ];

        $selected = fake()->randomElement($types);

        return [
            'name' => $selected['name'],
            'description' => $selected['description'],
        ];
    }
}
