<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use App\Models\Rack;
use App\Models\Vendor;
use App\Models\DeviceCategory;
use App\Models\DeviceType;
use App\Models\OperatingSystem;
use App\Models\Device;
use App\Models\DeviceInterface;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Create Permissions
        $permissions = [
            'manage users',
            'manage master data',
            'manage devices',
            'manage maintenance',
            'view dashboard',
            'view reports',
        ];

        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
        }

        // 2. Create Roles and Assign Permissions
        $superAdminRole = Role::firstOrCreate([
            'name' => 'Super Admin',
            'guard_name' => 'web',
        ]);
        // Super Admin gets all permissions via gate wildcard, but let's assign explicitly too
        $superAdminRole->givePermissionTo(Permission::all());

        $netAdminRole = Role::firstOrCreate([
            'name' => 'Network Administrator',
            'guard_name' => 'web',
        ]);
        $netAdminRole->givePermissionTo([
            'manage master data',
            'manage devices',
            'view dashboard',
            'view reports',
        ]);

        $technicianRole = Role::firstOrCreate([
            'name' => 'Technician',
            'guard_name' => 'web',
        ]);
        $technicianRole->givePermissionTo([
            'manage maintenance',
            'view dashboard',
            'view reports',
        ]);

        $viewerRole = Role::firstOrCreate([
            'name' => 'Viewer',
            'guard_name' => 'web',
        ]);
        $viewerRole->givePermissionTo([
            'view dashboard',
            'view reports',
        ]);

        // 3. Create Default Users
        $usersInfo = [
            [
                'name' => 'Super Admin User',
                'email' => 'admin@cims.com',
                'role' => 'Super Admin',
            ],
            [
                'name' => 'Network Administrator User',
                'email' => 'netadmin@cims.com',
                'role' => 'Network Administrator',
            ],
            [
                'name' => 'Technician User',
                'email' => 'tech@cims.com',
                'role' => 'Technician',
            ],
            [
                'name' => 'Viewer User',
                'email' => 'viewer@cims.com',
                'role' => 'Viewer',
            ],
        ];

        foreach ($usersInfo as $userInfo) {
            $user = User::firstOrCreate([
                'name' => $userInfo['name'],
                'email' => $userInfo['email'],
                'password' => bcrypt('password'),
            ]);
            $user->assignRole($userInfo['role']);
        }

        // 4. Seed Master Data

        // Operating Systems (NEW - per TASK_001 spec)
        $routerOS = OperatingSystem::create([
            'name' => 'RouterOS',
            'vendor' => 'MikroTik',
            'version' => '7.14',
            'description' => 'MikroTik RouterOS untuk perangkat router dan switch MikroTik.',
        ]);

        $iosXE = OperatingSystem::create([
            'name' => 'IOS-XE',
            'vendor' => 'Cisco',
            'version' => '17.3.5',
            'description' => 'Cisco IOS-XE untuk ISR dan Catalyst series.',
        ]);

        $ios = OperatingSystem::create([
            'name' => 'IOS',
            'vendor' => 'Cisco',
            'version' => '15.9',
            'description' => 'Cisco IOS klasik untuk perangkat legacy.',
        ]);

        $unifiOS = OperatingSystem::create([
            'name' => 'UniFi OS',
            'vendor' => 'Ubiquiti',
            'version' => '3.2',
            'description' => 'Ubiquiti UniFi OS untuk perangkat UniFi.',
        ]);

        $fortiOS = OperatingSystem::create([
            'name' => 'FortiOS',
            'vendor' => 'Fortinet',
            'version' => '7.4',
            'description' => 'Fortinet FortiOS untuk firewall FortiGate.',
        ]);

        // Device Types (NEW - per TASK_001 spec)
        $coreRouterType = DeviceType::create([
            'name' => 'Core Router',
            'description' => 'Router utama backbone jaringan kampus.',
        ]);

        $distSwitchType = DeviceType::create([
            'name' => 'Distribution Switch',
            'description' => 'Switch layer distribusi untuk segmentasi jaringan.',
        ]);

        $accessSwitchType = DeviceType::create([
            'name' => 'Access Switch',
            'description' => 'Switch akses untuk endpoint pengguna.',
        ]);

        $indoorAPType = DeviceType::create([
            'name' => 'Indoor Access Point',
            'description' => 'Access point untuk penggunaan dalam ruangan.',
        ]);

        $outdoorAPType = DeviceType::create([
            'name' => 'Outdoor Access Point',
            'description' => 'Access point untuk penggunaan luar ruangan.',
        ]);

        $rackServerType = DeviceType::create([
            'name' => 'Rack Server',
            'description' => 'Server fisik berbentuk rack-mount.',
        ]);

        // Buildings
        $rektorat = Building::create([
            'name' => 'Gedung Rektorat',
            'code' => 'REK',
            'description' => 'Gedung pusat administrasi dan rektorat.',
        ]);

        $labti = Building::create([
            'name' => 'Gedung Laboratorium TI',
            'code' => 'LABTI',
            'description' => 'Gedung pusat laboratorium Fakultas Teknologi Informasi.',
        ]);

        // Floors
        $rekFloor1 = Floor::create([
            'building_id' => $rektorat->id,
            'name' => 'Lantai 1 Rektorat',
            'level' => 1,
            'description' => 'Lantai 1 Gedung Rektorat.',
        ]);

        $labtiFloor2 = Floor::create([
            'building_id' => $labti->id,
            'name' => 'Lantai 2 Lab TI',
            'level' => 2,
            'description' => 'Lantai 2 Gedung Lab TI.',
        ]);

        // Rooms
        $serverRoom = Room::create([
            'floor_id' => $rekFloor1->id,
            'name' => 'Ruang Server Utama',
            'code' => 'RS-101',
            'description' => 'Data Center dan Ruang Server Utama Kampus.',
        ]);

        $netLab = Room::create([
            'floor_id' => $labtiFloor2->id,
            'name' => 'Laboratorium Jaringan Komputer',
            'code' => 'LAB-204',
            'description' => 'Laboratorium praktikum jaringan dan cisco academy.',
        ]);

        // Racks
        $rackA = Rack::create([
            'room_id' => $serverRoom->id,
            'name' => 'Rack A - Core',
            'capacity' => 42,
            'code' => 'RCK-A',
            'description' => 'Rak utama untuk perangkat Core Router dan Switch.',
        ]);

        $rackB = Rack::create([
            'room_id' => $serverRoom->id,
            'name' => 'Rack B - Distribution',
            'capacity' => 42,
            'code' => 'RCK-B',
            'description' => 'Rak sekunder untuk Server dan UPS.',
        ]);

        // Vendors
        $cisco = Vendor::create([
            'name' => 'Cisco Systems',
            'contact_person' => 'John Doe',
            'email' => 'sales@cisco-indonesia.com',
            'phone' => '021-5551234',
            'address' => 'Sudirman Central Business District, Jakarta',
        ]);

        $mikrotik = Vendor::create([
            'name' => 'MikroTik SIA',
            'contact_person' => 'Jane Smith',
            'email' => 'support@mikrotik.com',
            'phone' => '+371 67317700',
            'address' => 'Riga, Latvia',
        ]);

        $ubiquiti = Vendor::create([
            'name' => 'Ubiquiti Networks',
            'contact_person' => 'Ali',
            'email' => 'sales@ubnt.com',
            'phone' => '021-9998887',
            'address' => 'New York, USA',
        ]);

        // Device Categories
        $routerCat = DeviceCategory::create([
            'name' => 'Router',
            'description' => 'Perangkat routing jaringan layer 3.',
        ]);

        $switchCat = DeviceCategory::create([
            'name' => 'Switch',
            'description' => 'Perangkat switching jaringan layer 2/3.',
        ]);

        $apCat = DeviceCategory::create([
            'name' => 'Access Point',
            'description' => 'Perangkat wireless access point.',
        ]);

        $serverCat = DeviceCategory::create([
            'name' => 'Server',
            'description' => 'Perangkat komputer server.',
        ]);

        // 5. Seed Devices (with new fields)
        $coreRouter = Device::create([
            'name' => 'Core Router Rektorat',
            'hostname' => 'cr-rek-01',
            'ip_address' => '10.10.10.1',
            'mac_address' => '00:1A:2B:3C:4D:5E',
            'vendor_id' => $cisco->id,
            'device_category_id' => $routerCat->id,
            'operating_system_id' => $iosXE->id,
            'device_type_id' => $coreRouterType->id,
            'model' => 'Cisco ISR 4451',
            'serial_number' => 'FTX1928A4X',
            'firmware' => 'IOS-XE 17.3.5',
            'purchase_date' => '2025-01-15',
            'warranty' => '3 Years',
            'username' => 'admin',
            'building_id' => $rektorat->id,
            'floor_id' => $rekFloor1->id,
            'room_id' => $serverRoom->id,
            'rack_id' => $rackA->id,
            'status' => 'active',
            'notes' => 'Router utama yang menghubungkan kampus ke ISP.',
        ]);

        $distSwitch = Device::create([
            'name' => 'Distribution Switch Rektorat',
            'hostname' => 'sw-rek-dist-01',
            'ip_address' => '10.10.10.2',
            'mac_address' => '00:1A:2B:3C:4D:5F',
            'vendor_id' => $cisco->id,
            'device_category_id' => $switchCat->id,
            'operating_system_id' => $iosXE->id,
            'device_type_id' => $distSwitchType->id,
            'model' => 'Catalyst 9300',
            'serial_number' => 'FOC2345U9X',
            'firmware' => 'IOS-XE 17.6.1',
            'purchase_date' => '2025-02-10',
            'warranty' => '3 Years',
            'username' => 'admin',
            'building_id' => $rektorat->id,
            'floor_id' => $rekFloor1->id,
            'room_id' => $serverRoom->id,
            'rack_id' => $rackA->id,
            'status' => 'active',
            'notes' => 'Switch distribusi utama lantai 1 Rektorat.',
        ]);

        $apLab = Device::create([
            'name' => 'Access Point Lab Jaringan',
            'hostname' => 'ap-lab-jaringan-01',
            'ip_address' => '10.20.10.50',
            'mac_address' => '24:A4:3C:D9:8A:F1',
            'vendor_id' => $ubiquiti->id,
            'device_category_id' => $apCat->id,
            'operating_system_id' => $unifiOS->id,
            'device_type_id' => $indoorAPType->id,
            'model' => 'UniFi U6 Pro',
            'serial_number' => '24A43CD98AF1',
            'firmware' => '6.5.64',
            'purchase_date' => '2025-05-20',
            'warranty' => '1 Year',
            'building_id' => $labti->id,
            'floor_id' => $labtiFloor2->id,
            'room_id' => $netLab->id,
            'status' => 'active',
            'notes' => 'Access point untuk kebutuhan praktikum mahasiswa.',
        ]);

        // 6. Seed Device Interfaces (NEW - per TASK_001 spec)
        // Core Router Interfaces
        DeviceInterface::create([
            'device_id' => $coreRouter->id,
            'interface_name' => 'GigabitEthernet0/0',
            'ip_address' => '203.0.113.1',
            'subnet' => '255.255.255.252',
            'gateway' => '203.0.113.2',
            'mac_address' => '00:1A:2B:3C:4D:01',
            'interface_type' => 'ethernet',
            'interface_status' => 'up',
            'speed' => '1Gbps',
            'mtu' => 1500,
            'description' => 'WAN - Uplink ke ISP',
        ]);

        DeviceInterface::create([
            'device_id' => $coreRouter->id,
            'interface_name' => 'GigabitEthernet0/1',
            'ip_address' => '10.10.10.1',
            'subnet' => '255.255.255.0',
            'mac_address' => '00:1A:2B:3C:4D:02',
            'interface_type' => 'ethernet',
            'interface_status' => 'up',
            'speed' => '1Gbps',
            'mtu' => 1500,
            'description' => 'LAN - Core Network',
        ]);

        DeviceInterface::create([
            'device_id' => $coreRouter->id,
            'interface_name' => 'GigabitEthernet0/2',
            'ip_address' => '10.10.20.1',
            'subnet' => '255.255.255.0',
            'mac_address' => '00:1A:2B:3C:4D:03',
            'interface_type' => 'ethernet',
            'interface_status' => 'up',
            'speed' => '1Gbps',
            'mtu' => 1500,
            'description' => 'LAN - Distribution Segment',
        ]);

        DeviceInterface::create([
            'device_id' => $coreRouter->id,
            'interface_name' => 'GigabitEthernet0/3',
            'interface_type' => 'ethernet',
            'interface_status' => 'down',
            'speed' => '1Gbps',
            'mtu' => 1500,
            'description' => 'Reserved - Future expansion',
        ]);

        DeviceInterface::create([
            'device_id' => $coreRouter->id,
            'interface_name' => 'Loopback0',
            'ip_address' => '10.255.255.1',
            'subnet' => '255.255.255.255',
            'interface_type' => 'loopback',
            'interface_status' => 'up',
            'description' => 'Management Loopback',
        ]);

        // Distribution Switch Interfaces
        DeviceInterface::create([
            'device_id' => $distSwitch->id,
            'interface_name' => 'GigabitEthernet1/0/1',
            'ip_address' => '10.10.10.2',
            'subnet' => '255.255.255.0',
            'gateway' => '10.10.10.1',
            'mac_address' => '00:1A:2B:3C:5D:01',
            'interface_type' => 'ethernet',
            'interface_status' => 'up',
            'speed' => '1Gbps',
            'mtu' => 1500,
            'description' => 'Uplink to Core Router',
        ]);

        DeviceInterface::create([
            'device_id' => $distSwitch->id,
            'interface_name' => 'GigabitEthernet1/0/2',
            'ip_address' => '10.10.30.1',
            'subnet' => '255.255.255.0',
            'mac_address' => '00:1A:2B:3C:5D:02',
            'interface_type' => 'ethernet',
            'interface_status' => 'up',
            'speed' => '1Gbps',
            'mtu' => 1500,
            'description' => 'Downlink to Access Switch Lt.1',
        ]);

        DeviceInterface::create([
            'device_id' => $distSwitch->id,
            'interface_name' => 'GigabitEthernet1/0/3',
            'mac_address' => '00:1A:2B:3C:5D:03',
            'interface_type' => 'ethernet',
            'interface_status' => 'down',
            'speed' => '1Gbps',
            'mtu' => 1500,
            'description' => 'Reserved port',
        ]);

        DeviceInterface::create([
            'device_id' => $distSwitch->id,
            'interface_name' => 'Vlan10',
            'ip_address' => '10.10.10.2',
            'subnet' => '255.255.255.0',
            'interface_type' => 'vlan',
            'interface_status' => 'up',
            'description' => 'Management VLAN',
        ]);

        // Access Point Interfaces
        DeviceInterface::create([
            'device_id' => $apLab->id,
            'interface_name' => 'eth0',
            'ip_address' => '10.20.10.50',
            'subnet' => '255.255.255.0',
            'gateway' => '10.20.10.1',
            'mac_address' => '24:A4:3C:D9:8A:F1',
            'interface_type' => 'ethernet',
            'interface_status' => 'up',
            'speed' => '1Gbps',
            'mtu' => 1500,
            'description' => 'Wired uplink (PoE)',
        ]);

        DeviceInterface::create([
            'device_id' => $apLab->id,
            'interface_name' => 'wlan0',
            'mac_address' => '24:A4:3C:D9:8A:F2',
            'interface_type' => 'wireless',
            'interface_status' => 'up',
            'speed' => '1.2Gbps',
            'description' => '5GHz Radio - WiFi 6',
        ]);

        DeviceInterface::create([
            'device_id' => $apLab->id,
            'interface_name' => 'wlan1',
            'mac_address' => '24:A4:3C:D9:8A:F3',
            'interface_type' => 'wireless',
            'interface_status' => 'up',
            'speed' => '574Mbps',
            'description' => '2.4GHz Radio - WiFi 6',
        ]);

        // 7. Seed Historical Monitoring Logs (Past 24 Hours)
        $devices = Device::all();
        $now = \Carbon\Carbon::now();
        foreach ($devices as $dev) {
            for ($i = 24; $i >= 0; $i--) {
                $checkTime = $now->copy()->subHours($i);

                // Add fluctuation to metrics
                $latency = rand(5, 30);
                $cpu = rand(15, 65);
                $ram = rand(30, 80);
                $storage = rand(45, 60);
                $temp = rand(40, 68);
                $uptime = 3600 * (25 - $i);
                $rx = rand(1000000, 50000000);
                $tx = rand(500000, 25000000);

                \App\Models\MonitoringLog::create([
                    'device_id' => $dev->id,
                    'status' => 'online',
                    'ping_latency_ms' => $latency,
                    'packet_loss_percent' => 0,
                    'cpu_usage_percent' => $cpu,
                    'ram_usage_percent' => $ram,
                    'storage_usage_percent' => $storage,
                    'temperature_celsius' => $temp,
                    'uptime_seconds' => $uptime,
                    'bandwidth_rx_bps' => $rx,
                    'bandwidth_tx_bps' => $tx,
                    'checked_at' => $checkTime,
                ]);
            }

            // Also seed current DeviceMetric
            \App\Models\DeviceMetric::create([
                'device_id' => $dev->id,
                'last_ping_status' => 'online',
                'last_ping_latency_ms' => rand(5, 20),
                'last_packet_loss_percent' => 0,
                'last_cpu_usage_percent' => rand(20, 50),
                'last_ram_usage_percent' => rand(40, 70),
                'last_storage_usage_percent' => 55,
                'last_temperature_celsius' => 45,
                'last_uptime_seconds' => 3600 * 25,
                'last_interface_status' => [
                    ['name' => 'ether1-wan', 'status' => 'up', 'speed' => '1Gbps'],
                    ['name' => 'ether2-lan', 'status' => 'up', 'speed' => '1Gbps']
                ],
                'last_bandwidth_rx_bps' => rand(1000000, 50000000),
                'last_bandwidth_tx_bps' => rand(500000, 25000000),
                'last_checked_at' => $now,
            ]);
        }

        // 8. Seed Maintenance Tickets
        $tech = \App\Models\User::where('email', 'tech@cims.com')->first();

        if ($tech && $coreRouter && $distSwitch && $apLab) {
            \App\Models\MaintenanceTicket::create([
                'device_id' => $coreRouter->id,
                'technician_id' => $tech->id,
                'title' => 'Routine Dusting and Port Check',
                'description' => 'Clean physical hardware dust filters and verify active port integrity.',
                'status' => 'completed',
                'scheduled_at' => $now->copy()->subDays(3),
                'notes' => 'Completed routine maintenance. Cleaned all filters. Port status is 100% stable.',
            ]);

            \App\Models\MaintenanceTicket::create([
                'device_id' => $apLab->id,
                'technician_id' => $tech->id,
                'title' => 'Investigate Intermittent Wireless Dropouts',
                'description' => 'Students report wireless speed drops during afternoon classes.',
                'status' => 'in_progress',
                'scheduled_at' => $now->copy()->subDay(),
                'notes' => 'Analyzing channels and interference. Might need to reschedule to channel 6.',
            ]);

            \App\Models\MaintenanceTicket::create([
                'device_id' => $distSwitch->id,
                'technician_id' => $tech->id,
                'title' => 'Scheduled OS firmware Upgrade',
                'description' => 'Upgrade IOS-XE firmware from 17.6.1 to 17.6.4 to resolve security vulnerabilities.',
                'status' => 'pending',
                'scheduled_at' => $now->copy()->addDays(5),
            ]);
        }
    }
}
