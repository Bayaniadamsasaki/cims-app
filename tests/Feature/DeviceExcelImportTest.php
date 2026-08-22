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
