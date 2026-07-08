<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\Vendor;
use App\Models\DeviceCategory;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'hostname' => fake()->unique()->domainWord() . '-' . fake()->numerify('##'),
            'ip_address' => fake()->localIpv4(),
            'mac_address' => fake()->macAddress(),
            'vendor_id' => Vendor::factory(),
            'device_category_id' => DeviceCategory::factory(),
            'model' => fake()->bothify('Model-????-####'),
            'serial_number' => strtoupper(fake()->unique()->bothify('???########')),
            'firmware' => fake()->numerify('#.#.##'),
            'purchase_date' => fake()->dateTimeBetween('-3 years', 'now'),
            'warranty' => fake()->randomElement(['1 Year', '2 Years', '3 Years', '5 Years']),
            'building_id' => Building::factory(),
            'floor_id' => Floor::factory(),
            'room_id' => Room::factory(),
            'status' => fake()->randomElement(['active', 'maintenance', 'inactive']),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
