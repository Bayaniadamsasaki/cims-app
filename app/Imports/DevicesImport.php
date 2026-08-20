<?php

namespace App\Imports;

use App\Models\Device;
use App\Models\Vendor;
use App\Models\DeviceCategory;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;

class DevicesImport implements ToCollection, WithStartRow
{
    /**
     * Data asli pada sheet Excel mulai pada baris ke-4.
     */
    public function startRow(): int
    {
        return 4;
    }

    /**
     * Memproses kumpulan baris dari Excel dan melakukan updateOrCreate ke model Device.
     */
    public function collection(Collection $rows)
    {
        // Pastikan kategori default Router tersedia
        $defaultCategory = DeviceCategory::firstOrCreate(['name' => 'Router']);

        foreach ($rows as $row) {
            // Melewati baris jika kolom nama (index 1) kosong
            if (!isset($row[1]) || empty(trim((string)$row[1]))) {
                continue;
            }

            $deviceName = trim((string)$row[1]);
            $vendorName = isset($row[4]) && !empty(trim((string)$row[4])) ? trim((string)$row[4]) : 'MikroTik';
            $vendor = Vendor::firstOrCreate(['name' => $vendorName]);

            $macAddress = isset($row[12]) && !empty(trim((string)$row[12])) ? trim((string)$row[12]) : null;
            $sn = isset($row[8]) && !empty(trim((string)$row[8])) ? trim((string)$row[8]) : null;
            $rawPass = isset($row[10]) && !empty(trim((string)$row[10])) ? trim((string)$row[10]) : null;

            // Kunci pencarian unik: MAC Address -> Serial Number -> Nama Perangkat
            if ($macAddress) {
                $matchKey = ['mac_address' => $macAddress];
            } elseif ($sn) {
                $matchKey = ['serial_number' => $sn];
            } else {
                $matchKey = ['name' => $deviceName];
            }

            Device::updateOrCreate(
                $matchKey,
                [
                    'name'                => $deviceName,
                    'hostname'            => isset($row[2]) ? trim((string)$row[2]) : $deviceName,
                    'vendor_id'           => $vendor->id,
                    'device_category_id'  => $defaultCategory->id,
                    'model'               => isset($row[5]) ? trim((string)$row[5]) : null,
                    'firmware'            => isset($row[7]) ? trim((string)$row[7]) : null,
                    'serial_number'       => $sn,
                    'username'            => isset($row[9]) ? trim((string)$row[9]) : null,
                    'password_encrypted'  => $rawPass ? encrypt($rawPass) : null,
                    'ip_address'          => isset($row[14]) ? trim((string)$row[14]) : null,
                    'mac_address'         => $macAddress,
                    'status'              => (isset($row[18]) && strtolower(trim((string)$row[18])) === 'active') ? 'active' : 'active',
                    'source'              => 'inventory',
                ]
            );
        }
    }
}
