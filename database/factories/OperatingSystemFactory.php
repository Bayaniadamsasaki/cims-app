<?php

namespace Database\Factories;

use App\Models\OperatingSystem;
use Illuminate\Database\Eloquent\Factories\Factory;

class OperatingSystemFactory extends Factory
{
    protected $model = OperatingSystem::class;

    public function definition(): array
    {
        $osList = [
            ['name' => 'RouterOS', 'vendor' => 'MikroTik', 'version' => '7.x'],
            ['name' => 'IOS-XE', 'vendor' => 'Cisco', 'version' => '17.x'],
            ['name' => 'IOS', 'vendor' => 'Cisco', 'version' => '15.x'],
            ['name' => 'NX-OS', 'vendor' => 'Cisco', 'version' => '10.x'],
            ['name' => 'FortiOS', 'vendor' => 'Fortinet', 'version' => '7.x'],
            ['name' => 'JunOS', 'vendor' => 'Juniper', 'version' => '22.x'],
            ['name' => 'UniFi OS', 'vendor' => 'Ubiquiti', 'version' => '3.x'],
            ['name' => 'Ubuntu Server', 'vendor' => 'Canonical', 'version' => '22.04 LTS'],
        ];

        $selected = fake()->randomElement($osList);

        return [
            'name' => $selected['name'],
            'vendor' => $selected['vendor'],
            'version' => $selected['version'],
            'description' => fake()->optional()->sentence(),
        ];
    }
}
