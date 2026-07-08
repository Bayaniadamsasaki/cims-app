<?php

namespace Database\Factories;

use App\Models\DeviceInterface;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeviceInterfaceFactory extends Factory
{
    protected $model = DeviceInterface::class;

    public function definition(): array
    {
        $interfaceTypes = ['ethernet', 'wireless', 'vlan', 'loopback', 'bridge', 'tunnel'];
        $interfaceNames = ['ether1', 'ether2', 'ether3', 'ether4', 'ether5', 'wlan1', 'bridge1', 'vlan10', 'GigabitEthernet0/0', 'GigabitEthernet0/1', 'FastEthernet0/0'];

        return [
            'device_id' => Device::factory(),
            'interface_name' => fake()->randomElement($interfaceNames),
            'ip_address' => fake()->optional(0.7)->localIpv4(),
            'subnet' => fake()->optional(0.7)->randomElement(['255.255.255.0', '255.255.0.0', '255.255.255.252']),
            'gateway' => fake()->optional(0.5)->localIpv4(),
            'mac_address' => fake()->optional(0.8)->macAddress(),
            'interface_type' => fake()->randomElement($interfaceTypes),
            'interface_status' => fake()->randomElement(['up', 'down', 'disabled']),
            'speed' => fake()->optional(0.6)->randomElement(['10Mbps', '100Mbps', '1Gbps', '10Gbps']),
            'mtu' => fake()->optional(0.5)->randomElement([1500, 1400, 9000]),
            'description' => fake()->optional(0.4)->sentence(),
        ];
    }
}
