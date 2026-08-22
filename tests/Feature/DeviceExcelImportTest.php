<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

class DeviceExcelImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_device_audit_excel_is_imported_with_its_interfaces(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->post(route('devices.import'), ['file' => $this->auditWorkbook()]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');

        $device = Device::where('serial_number', 'TEST-SN-001')->first();
        $this->assertNotNull($device, 'Perangkat dari file audit tidak tersimpan ke database.');
        $this->assertSame('192.168.91.1', $device->ip_address);
        $this->assertSame('RB450Gx4', $device->model);
        $this->assertSame('7.23.2 (stable)', $device->firmware);
        $this->assertSame(2, $device->deviceInterfaces()->count());

        $ether1 = $device->deviceInterfaces()->where('interface_name', 'ether1')->first();
        $this->assertSame('118.98.127.21', $ether1->ip_address);
        $this->assertSame('255.255.255.248', $ether1->subnet);
        $this->assertSame('ethernet', $ether1->interface_type);
        $this->assertSame('up', $ether1->interface_status);

        $ether2 = $device->deviceInterfaces()->where('interface_name', 'ether2')->first();
        $this->assertSame('192.168.90.1', $ether2->ip_address);
        $this->assertSame('down', $ether2->interface_status);
    }

    public function test_neighbor_sheet_is_imported_and_exposed_to_the_device_detail_view(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('devices.import'), ['file' => $this->auditWorkbook()])
            ->assertSessionHas('success');

        $device = Device::where('serial_number', 'TEST-SN-001')->firstOrFail();

        // Baris ether4 hanya berisi "-" di semua kolom, jadi tidak ikut tersimpan.
        $this->assertSame(4, $device->deviceNeighbors()->count());

        $balkon = $device->deviceNeighbors()->where('mac_address', 'F4:1E:57:A2:88:3C')->first();
        $this->assertNotNull($balkon);
        $this->assertSame('ether2', $balkon->interface_name);
        $this->assertSame('192.168.90.253', $balkon->ip_address);
        $this->assertSame('MIkrotik_Balkon_FK', $balkon->identity);
        $this->assertSame('MikroTik', $balkon->platform);
        $this->assertSame('RB450Gx4', $balkon->board);
        $this->assertSame('7.23.2', $balkon->version);

        // Kolom "-" jadi null, bukan literal "-".
        $polos = $device->deviceNeighbors()->where('mac_address', 'D0:50:99:9E:1C:DA')->firstOrFail();
        $this->assertNull($polos->ip_address);
        $this->assertNull($polos->identity);

        // Satu interface boleh muncul lebih dari sekali.
        $this->assertSame(2, $device->deviceNeighbors()->where('interface_name', 'ether3')->count());

        // Halaman inventaris harus membawa relasi ini agar modal detail bisa
        // menampilkannya tanpa request tambahan.
        $this->actingAs($user)
            ->get(route('devices.index'))
            ->assertInertia(fn ($page) => $page
                ->has('devices.0.device_neighbors', 4)
                ->has('devices.0.device_neighbors.0', fn ($neighbor) => $neighbor
                    ->hasAll(['interface_name', 'mac_address', 'ip_address', 'identity', 'platform', 'board', 'version'])
                    ->etc())
                ->where('devices.0.device_neighbors_count', 4)
                ->etc());
    }

    public function test_reimport_replaces_neighbors_instead_of_duplicating_them(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('devices.import'), ['file' => $this->auditWorkbook()]);
        $this->actingAs($user)->post(route('devices.import'), ['file' => $this->auditWorkbook()]);

        $device = Device::where('serial_number', 'TEST-SN-001')->firstOrFail();

        $this->assertSame(1, Device::count());
        $this->assertSame(4, $device->deviceNeighbors()->count());
    }

    public function test_unrecognised_file_reports_failure_instead_of_false_success(): void
    {
        // Kolom B kosong di semua baris, jadi tidak ada satu pun baris yang layak diimport.
        $csv = UploadedFile::fake()->createWithContent(
            'bukan-inventaris.csv',
            "judul\nsubjudul\n\nbaris-tanpa-kolom-b\nbaris-tanpa-kolom-b\n"
        );

        $response = $this->actingAs(User::factory()->create())
            ->post(route('devices.import'), ['file' => $csv]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionMissing('success');
        $this->assertSame(0, Device::count());
    }

    public function test_ubg_import_command_signals_failure_for_a_missing_file(): void
    {
        // Kontrak yang diandalkan controller: Artisan::call() tidak melempar
        // exception, jadi exit code adalah satu-satunya penanda kegagalan.
        $this->assertSame(1, Artisan::call('import:ubg-excel', ['file' => 'tidak/ada/berkas.xlsx']));
    }

    /**
     * Build an .xlsx in the shape produced by the CIMS MikroTik audit tool.
     * PhpSpreadsheet writes a shared string table, so this also covers files
     * that have been re-saved through Excel or LibreOffice.
     */
    private function auditWorkbook(): UploadedFile
    {
        $spreadsheet = new Spreadsheet();

        $summary = $spreadsheet->getActiveSheet();
        $summary->setTitle('Ringkasan Perangkat');
        $summary->fromArray([
            ['AUDIT ROUTER MIKROTIK'],
            ['Waktu pengambilan: 2026-08-03T14:23:25'],
            [],
            ['Parameter', 'Nilai'],
            ['Host / IP Router', '192.168.91.1'],
            ['Merek', 'MikroTik'],
            ['Board', 'RB450Gx4'],
            ['Versi Software (Patch)', '7.23.2 (stable)'],
            ['Serial Number (SN)', 'TEST-SN-001'],
            ['Username', 'admin'],
            ['Password', 'kata-sandi-uji'],
        ], null, 'A1');

        $interfaces = $spreadsheet->createSheet();
        $interfaces->setTitle('Interface');
        $interfaces->fromArray([
            ['Nama Interface', 'Jenis Interface', 'MAC Address', 'Status', 'Bridge', 'MTU', 'Jumlah IP', 'IP Address (gabungan)', 'Alokasi IP', 'Comment'],
            ['ether1', 'ether', 'AA:BB:CC:DD:EE:01', 'Running', '', 1500, 1, '118.98.127.21/29', '', 'Uplink ISP'],
            ['ether2', 'ether', 'AA:BB:CC:DD:EE:02', 'Disabled', '', 1500, 1, '192.168.90.1/24', '', 'LAN Kantor'],
        ], null, 'A1');

        $ipAddresses = $spreadsheet->createSheet();
        $ipAddresses->setTitle('IP Address');
        $ipAddresses->fromArray([
            ['Interface', 'Address', 'Network', 'Tipe'],
            ['ether1', '118.98.127.21/29', '118.98.127.16', 'static'],
        ], null, 'A1');

        // Satu interface bisa punya beberapa neighbor, dan hanya MAC yang selalu
        // terisi — baris "-" murni placeholder dan harus diabaikan importer.
        $neighbors = $spreadsheet->createSheet();
        $neighbors->setTitle('Neighbor');
        $neighbors->fromArray([
            ['Interface', 'IP Address', 'MAC Address', 'Identity', 'Platform', 'Board', 'Version'],
            ['ether1', '-', 'D0:50:99:9E:1C:DA', '-', '-', '-', '-'],
            ['ether2', '192.168.90.253', 'F4:1E:57:A2:88:3C', 'MIkrotik_Balkon_FK', 'MikroTik', 'RB450Gx4', '7.23.2'],
            ['ether3', '-', '04:0E:3C:9F:6C:FD', '-', '-', '-', '-'],
            ['ether3', '-', '28:D2:44:F8:2C:29', '-', '-', '-', '-'],
            ['ether4', '-', '-', '-', '-', '-', '-'],
        ], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'cims_audit_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            'audit_perangkat.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }
}
