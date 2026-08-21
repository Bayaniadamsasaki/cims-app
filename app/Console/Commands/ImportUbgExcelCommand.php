<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use App\Models\Vendor;
use App\Models\DeviceCategory;
use App\Models\OperatingSystem;
use App\Models\Device;
use App\Models\DeviceInterface;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class ImportUbgExcelCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:ubg-excel {file=Docs/Inventaris Jaringan UBG.xlsx}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import Building, Room, Device and Interface inventory from UBG Excel file';

    public function handle()
    {
        $arg = $this->argument('file');
        $filePath = str_starts_with($arg, '/') || str_starts_with($arg, 'C:\\') || str_starts_with($arg, 'c:\\') ? $arg : base_path($arg);
        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return 1;
        }

        $this->info("Parsing Excel file via Python parser: {$filePath}...");

        $scriptPath = base_path('app/Console/Commands/parse_excel.py');
        $process = new Process(['python', $scriptPath, $filePath]);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->error("Python parser failed: " . $process->getErrorOutput());
            return 1;
        }

        $jsonOutput = $process->getOutput();
        $data = json_decode($jsonOutput, true);

        if (!$data || isset($data['error'])) {
            $this->error("Invalid JSON data output: " . ($data['error'] ?? 'JSON decode error'));
            return 1;
        }

        $buildingNames = [
            'GU' => 'Gedung Utara',
            'GR' => 'Gedung Rektorat',
            'GB' => 'Gedung Barat',
            'GS' => 'Gedung Selatan',
            'GT' => 'Gedung Timur',
            'B' => 'Basement',
            'BLK' => 'Balkon',
        ];

        // 1. Process Gedung & Ruangan
        $this->info("Importing Gedung & Ruangan (" . count($data['gedung_ruangan']) . " entries)...");
        foreach ($data['gedung_ruangan'] as $row) {
            $kodeGedung = $row['kode_gedung'] ?? null;
            $kodeLantaiStr = $row['kode_lantai'] ?? '1';
            $kodeTempat = $row['kode_tempat'] ?? null;
            $namaRuangan = $row['nama_ruangan'] ?? 'Ruangan ' . ($row['kode_ruangan'] ?? '');

            if (!$kodeGedung) continue;

            $buildingName = $buildingNames[$kodeGedung] ?? "Gedung {$kodeGedung}";
            
            $building = Building::firstOrCreate(
                ['code' => $kodeGedung],
                ['name' => $buildingName, 'floors_count' => 1, 'rooms_count' => 0]
            );

            $floorNum = (int)floatval($kodeLantaiStr);
            if ($floorNum < 1) $floorNum = 1;

            if ($floorNum > $building->floors_count) {
                $building->update(['floors_count' => $floorNum]);
            }

            $floor = Floor::firstOrCreate(
                ['building_id' => $building->id, 'level' => $floorNum],
                ['name' => "Lantai {$floorNum}"]
            );

            if ($kodeTempat || $namaRuangan) {
                Room::firstOrCreate(
                    ['floor_id' => $floor->id, 'code' => $kodeTempat ?: Str::slug($namaRuangan)],
                    ['name' => $namaRuangan ?: "Ruang {$kodeTempat}"]
                );
            }
        }

        // Ensure every building has a Ruang Server
        \Illuminate\Support\Facades\Artisan::call('cims:ensure-server-rooms');

        // 2. Process Routers & Devices
        $this->info("Importing Routers & Devices (" . count($data['routers']) . " devices)...");

        $mikrotikVendor = Vendor::firstOrCreate(['name' => 'MikroTik'], ['contact_person' => 'Support', 'email' => 'support@mikrotik.com']);
        $routerCategory = DeviceCategory::firstOrCreate(['name' => 'Router'], ['description' => 'Networking Router Devices']);
        $routerOS = OperatingSystem::firstOrCreate(['name' => 'RouterOS'], ['version' => 'v6/v7']);

        foreach ($data['routers'] as $deviceData) {
            $this->saveDeviceWithInterfaces($deviceData, $mikrotikVendor, $routerCategory, $routerOS);
        }

        $this->info("Successfully imported all inventory data from UBG Excel file!");
        return 0;
    }

    private function saveDeviceWithInterfaces($deviceData, $vendor, $category, $os)
    {
        $roomId = null;
        $floorId = null;
        $buildingId = null;

        if (!empty($deviceData['posisi'])) {
            $room = Room::where('code', $deviceData['posisi'])->first();
            if ($room) {
                $roomId = $room->id;
                $floorId = $room->floor_id;
                $buildingId = $room->floor->building_id ?? null;
            }
        }

        $primaryIp = null;
        $primaryMac = null;

        foreach ($deviceData['interfaces'] as $iface) {
            if (!$primaryIp && !empty($iface['ip']) && $iface['ip'] !== '-') {
                $primaryIp = explode('/', $iface['ip'])[0];
            }
            if (!$primaryMac && !empty($iface['mac']) && $iface['mac'] !== '-') {
                $primaryMac = $iface['mac'];
            }
        }

        $sn = !empty($deviceData['serial_number']) ? $deviceData['serial_number'] : ('SN-' . strtoupper(Str::random(8)));
        $name = "{$deviceData['jenis']} MikroTik {$deviceData['board']}";

        $device = Device::updateOrCreate(
            ['serial_number' => $sn],
            [
                'name' => $name,
                'hostname' => $deviceData['hostname'] ?? $name,
                'ip_address' => $primaryIp,
                'mac_address' => $primaryMac,
                'vendor_id' => $vendor->id,
                'device_category_id' => $category->id,
                'operating_system_id' => $os->id,
                'model' => $deviceData['board'],
                'firmware' => $deviceData['firmware'],
                'username' => $deviceData['username'],
                'password_encrypted' => !empty($deviceData['password']) ? encrypt($deviceData['password']) : null,
                'building_id' => $buildingId,
                'floor_id' => $floorId,
                'room_id' => $roomId,
                'status' => 'active',
                'notes' => "Imported from UBG Excel. Bandwidth: " . ($deviceData['bandwidth'] ?? '-'),
            ]
        );

        foreach ($deviceData['interfaces'] as $iface) {
            if (empty($iface['name'])) continue;

            $ip = (!empty($iface['ip']) && $iface['ip'] !== '-') ? explode('/', $iface['ip'])[0] : null;
            $mac = (!empty($iface['mac']) && $iface['mac'] !== '-') ? $iface['mac'] : null;

            DeviceInterface::updateOrCreate(
                [
                    'device_id' => $device->id,
                    'interface_name' => $iface['name']
                ],
                [
                    'ip_address' => $ip,
                    'subnet' => !empty($iface['prefix']) ? "/{$iface['prefix']}" : null,
                    'mac_address' => $mac,
                    'interface_type' => $iface['type'] ?? 'Ethernet',
                    'interface_status' => (isset($iface['status']) && strtolower($iface['status']) === 'aktif') ? 'up' : 'down',
                    'description' => !empty($iface['bridge']) ? "Bridge: {$iface['bridge']}" : null
                ]
            );
        }
    }
}
