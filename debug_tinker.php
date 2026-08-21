<?php
require 'vendor/autoload.php';

use App\Models\Device;
use App\Models\Vendor;
use App\Models\DeviceCategory;

$vendor = Vendor::firstOrCreate(['name' => 'MikroTik']);
$category = DeviceCategory::firstOrCreate(['name' => 'Router']);

$device = Device::firstOrCreate(
    ['serial_number' => 'HD508CJZHSR'],
    [
        'name' => 'MikroTik_RB450Gx4_192.168.91.1',
        'hostname' => 'RB450Gx4',
        'vendor_id' => $vendor->id,
        'device_category_id' => $category->id,
        'ip_address' => '192.168.91.1',
        'model' => 'RB450Gx4',
        'firmware' => '7.23.2 (stable)',
        'username' => 'admin',
        'status' => 'active',
        'source' => 'inventory',
    ]
);

echo "Device ID: " . $device->id . "\n";
echo "Device name: " . $device->name . "\n";
echo "Serial: " . $device->serial_number . "\n";
echo "IP: " . $device->ip_address . "\n";
echo "Interfaces count: " . $device->deviceInterfaces()->count() . "\n";