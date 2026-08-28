<?php

namespace Tests\Feature;

use App\Models\HotspotVoucher;
use App\Models\User;
use App\Services\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery\MockInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Tests\TestCase;

class HotspotVoucherTest extends TestCase
{
    use RefreshDatabase;

    /** Router tujuan dipatok supaya test tidak bergantung pada .env lokal. */
    private const HOST = '192.168.88.1';

    protected function setUp(): void
    {
        parent::setUp();

        // HOTSPOT_ROUTER_HOST menang atas MIKROTIK_HOST, jadi keduanya dipatok
        // di sini supaya test tidak tergantung isi .env mesin pengembang.
        config([
            'services.hotspot.router_host' => null,
            'services.hotspot.default_profile' => null,
            'services.mikrotik.host' => self::HOST,
        ]);
    }

    public function test_new_vouchers_inherit_the_campus_default_profile(): void
    {
        config(['services.hotspot.default_profile' => 'mahasiswa']);

        $user = User::factory()->create();

        // Form manual tanpa memilih profile → ikut profile kampus.
        $this->actingAs($user)
            ->post(route('hotspot.vouchers.store'), ['nim' => '2101001'])
            ->assertSessionHasNoErrors();
        $this->assertSame('mahasiswa', HotspotVoucher::where('nim', '2101001')->firstOrFail()->profile);

        // Profile yang dipilih di form tidak boleh ditimpa.
        $this->actingAs($user)
            ->post(route('hotspot.vouchers.store'), ['nim' => '2101002', 'profile' => 'dosen'])
            ->assertSessionHasNoErrors();
        $this->assertSame('dosen', HotspotVoucher::where('nim', '2101002')->firstOrFail()->profile);

        // Import tanpa kolom/pilihan profile juga ikut profile kampus.
        $this->actingAs($user)->post(route('hotspot.vouchers.import'), [
            'file' => UploadedFile::fake()->createWithContent('nim.csv', "2101003,Mahasiswa C\n"),
        ])->assertSessionHas('success');
        $this->assertSame('mahasiswa', HotspotVoucher::where('nim', '2101003')->firstOrFail()->profile);
    }

    public function test_hotspot_router_host_overrides_the_monitoring_router(): void
    {
        config([
            'services.hotspot.router_host' => '192.168.137.136',
            'services.mikrotik.host' => '192.168.91.1',
        ]);

        $this->mock(MikrotikService::class, fn (MockInterface $mock) => $mock->shouldIgnoreMissing());

        $this->actingAs(User::factory()->create())
            ->post(route('hotspot.vouchers.store'), ['nim' => '2101001'])
            ->assertSessionHasNoErrors();

        $this->assertSame('192.168.137.136', HotspotVoucher::firstOrFail()->router_host);
    }

    public function test_manual_voucher_defaults_its_password_to_the_nim(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->post(route('hotspot.vouchers.store'), [
                'nim' => '2101001',
                'student_name' => 'Mahasiswa Uji',
                'program' => 'Teknik Informatika',
                'password' => '',
                'batch_label' => 'Angkatan 2021',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        $voucher = HotspotVoucher::firstOrFail();
        $this->assertSame('2101001', $voucher->nim);
        $this->assertSame('2101001', $voucher->password, 'Password harus otomatis sama dengan NIM.');
        $this->assertSame(HotspotVoucher::STATUS_PENDING, $voucher->status);
        $this->assertSame(self::HOST, $voucher->router_host);
        $this->assertNull($voucher->mikrotik_id, 'Router belum boleh disentuh sebelum push.');
    }

    public function test_duplicate_nim_on_the_same_router_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('hotspot.vouchers.store'), ['nim' => '2101001']);

        $this->actingAs($user)
            ->post(route('hotspot.vouchers.store'), ['nim' => '2101001'])
            ->assertSessionHasErrors('nim');

        $this->assertSame(1, HotspotVoucher::count());
    }

    public function test_excel_import_creates_pending_vouchers_from_arbitrary_column_order(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->post(route('hotspot.vouchers.import'), [
                'file' => $this->studentWorkbook(),
                'profile' => 'mahasiswa',
                'batch_label' => 'Angkatan 2026',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        // 3 NIM unik; baris duplikat dan baris tanpa NIM dilewati.
        $this->assertSame(3, HotspotVoucher::count());

        $first = HotspotVoucher::where('nim', '2101001')->firstOrFail();
        $this->assertSame('Mahasiswa A', $first->student_name);
        $this->assertSame('Teknik Informatika', $first->program);
        $this->assertSame('2101001', $first->password, 'Kolom password kosong = NIM.');
        $this->assertSame('mahasiswa', $first->profile);
        $this->assertSame('Angkatan 2026', $first->batch_label);
        $this->assertSame(HotspotVoucher::STATUS_PENDING, $first->status);

        // NIM panjang sering terbaca sebagai float oleh PhpSpreadsheet.
        $this->assertNotNull(HotspotVoucher::where('nim', '20220110123456')->first());

        // Kolom password yang diisi tidak boleh ditimpa NIM.
        $this->assertSame('rahasia123', HotspotVoucher::where('nim', '2101002')->firstOrFail()->password);
    }

    public function test_file_without_any_nim_reports_failure_instead_of_false_success(): void
    {
        $csv = UploadedFile::fake()->createWithContent(
            'bukan-mahasiswa.csv',
            "Laporan Keuangan\nTotal;Rp 0\n"
        );

        $response = $this->actingAs(User::factory()->create())
            ->post(route('hotspot.vouchers.import'), ['file' => $csv]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $response->assertSessionMissing('success');
        $this->assertSame(0, HotspotVoucher::count());
    }

    public function test_push_marks_synced_rows_and_records_per_row_failures(): void
    {
        $user = User::factory()->create();

        $ok = HotspotVoucher::create([
            'nim' => '2101001', 'password' => '2101001', 'router_host' => self::HOST,
            'status' => HotspotVoucher::STATUS_PENDING,
        ]);
        $bad = HotspotVoucher::create([
            'nim' => '2101002', 'password' => '2101002', 'router_host' => self::HOST,
            'status' => HotspotVoucher::STATUS_PENDING,
        ]);

        $this->mock(MikrotikService::class, function (MockInterface $mock) {
            $mock->shouldReceive('testConnection')->andReturn(['success' => true, 'identity' => 'uji']);
            $mock->shouldReceive('upsertHotspotUser')
                ->with(self::HOST, \Mockery::on(fn ($attr) => $attr['name'] === '2101001'))
                ->andReturn('*1A');
            $mock->shouldReceive('upsertHotspotUser')
                ->with(self::HOST, \Mockery::on(fn ($attr) => $attr['name'] === '2101002'))
                ->andThrow(new \RuntimeException('RouterOS menolak perintah: user profile not found'));
        });

        $this->actingAs($user)
            ->post(route('hotspot.vouchers.push'))
            ->assertRedirect()
            ->assertSessionHas('error'); // ada 1 kegagalan → flash error, bukan success

        $ok->refresh();
        $this->assertSame(HotspotVoucher::STATUS_SYNCED, $ok->status);
        $this->assertSame('*1A', $ok->mikrotik_id);
        $this->assertNotNull($ok->synced_at);
        $this->assertNull($ok->last_error);

        $bad->refresh();
        $this->assertSame(HotspotVoucher::STATUS_FAILED, $bad->status);
        $this->assertStringContainsString('user profile not found', $bad->last_error);
        $this->assertNull($bad->mikrotik_id);
    }

    public function test_push_aborts_when_the_router_is_unreachable(): void
    {
        $voucher = HotspotVoucher::create([
            'nim' => '2101001', 'password' => '2101001', 'router_host' => self::HOST,
            'status' => HotspotVoucher::STATUS_PENDING,
        ]);

        $this->mock(MikrotikService::class, function (MockInterface $mock) {
            $mock->shouldReceive('testConnection')->andReturn(['success' => false, 'error' => 'timeout']);
            $mock->shouldNotReceive('upsertHotspotUser');
        });

        $this->actingAs(User::factory()->create())
            ->post(route('hotspot.vouchers.push'))
            ->assertSessionHas('error');

        $this->assertSame(HotspotVoucher::STATUS_PENDING, $voucher->refresh()->status);
    }

    public function test_editing_credentials_sends_a_synced_voucher_back_to_pending(): void
    {
        $voucher = HotspotVoucher::create([
            'nim' => '2101001', 'password' => '2101001', 'router_host' => self::HOST,
            'status' => HotspotVoucher::STATUS_SYNCED, 'mikrotik_id' => '*1A', 'synced_at' => now(),
        ]);

        $user = User::factory()->create();

        // Ubah nama saja: status tetap synced.
        $this->actingAs($user)->post(route('hotspot.vouchers.update', $voucher->id), [
            'nim' => '2101001', 'student_name' => 'Nama Baru', 'password' => '2101001',
        ])->assertSessionHasNoErrors();
        $this->assertSame(HotspotVoucher::STATUS_SYNCED, $voucher->refresh()->status);

        // Ubah password: harus kembali pending agar dipush ulang.
        $this->actingAs($user)->post(route('hotspot.vouchers.update', $voucher->id), [
            'nim' => '2101001', 'student_name' => 'Nama Baru', 'password' => 'passwordbaru',
        ])->assertSessionHasNoErrors();
        $this->assertSame(HotspotVoucher::STATUS_PENDING, $voucher->refresh()->status);
    }

    public function test_index_lists_vouchers_with_stats_and_defers_router_lookups(): void
    {
        HotspotVoucher::create([
            'nim' => '2101001', 'password' => '2101001', 'router_host' => self::HOST,
            'status' => HotspotVoucher::STATUS_PENDING, 'batch_label' => 'Angkatan 2021',
        ]);
        HotspotVoucher::create([
            'nim' => '2101002', 'password' => '2101002', 'router_host' => self::HOST,
            'status' => HotspotVoucher::STATUS_SYNCED,
        ]);
        // Voucher milik router lain tidak boleh bocor ke halaman ini.
        HotspotVoucher::create([
            'nim' => '2101003', 'password' => '2101003', 'router_host' => '10.9.9.9',
            'status' => HotspotVoucher::STATUS_PENDING,
        ]);

        $this->mock(MikrotikService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('testConnection');
            $mock->shouldNotReceive('getHotspotProfiles');
        });

        $this->actingAs(User::factory()->create())
            ->get(route('hotspot.vouchers.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Hotspot/Vouchers')
                ->has('vouchers.data', 2)
                ->where('stats.total', 2)
                ->where('stats.pending', 1)
                ->where('stats.synced', 1)
                ->where('routerHost', self::HOST)
                ->has('batches', 1)
                ->etc());
    }

    public function test_search_filter_narrows_the_listing(): void
    {
        HotspotVoucher::create([
            'nim' => '2101001', 'student_name' => 'Budi Santoso', 'password' => '2101001',
            'router_host' => self::HOST, 'status' => HotspotVoucher::STATUS_PENDING,
        ]);
        HotspotVoucher::create([
            'nim' => '2101002', 'student_name' => 'Siti Aminah', 'password' => '2101002',
            'router_host' => self::HOST, 'status' => HotspotVoucher::STATUS_PENDING,
        ]);

        $this->mock(MikrotikService::class, fn (MockInterface $mock) => $mock->shouldIgnoreMissing());

        $this->actingAs(User::factory()->create())
            ->get(route('hotspot.vouchers.index', ['search' => 'Aminah']))
            ->assertInertia(fn ($page) => $page
                ->has('vouchers.data', 1)
                ->where('vouchers.data.0.nim', '2101002')
                ->etc());
    }

    public function test_csv_export_includes_the_password_column(): void
    {
        HotspotVoucher::create([
            'nim' => '2101001', 'student_name' => 'Budi Santoso', 'password' => '2101001',
            'router_host' => self::HOST, 'status' => HotspotVoucher::STATUS_PENDING,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('hotspot.vouchers.export'));

        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Password', $csv);
        $this->assertStringContainsString('Budi Santoso', $csv);
        $this->assertStringContainsString('2101001', $csv);
    }

    public function test_printable_cards_return_a_pdf(): void
    {
        HotspotVoucher::create([
            'nim' => '2101001', 'student_name' => 'Budi Santoso', 'password' => '2101001',
            'router_host' => self::HOST, 'status' => HotspotVoucher::STATUS_PENDING,
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('hotspot.vouchers.print'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_headerless_file_still_reads_column_a_as_nim(): void
    {
        $csv = UploadedFile::fake()->createWithContent(
            'tanpa-judul.csv',
            "2101001,Budi Santoso\n2101002,Siti Aminah\n"
        );

        $this->actingAs(User::factory()->create())
            ->post(route('hotspot.vouchers.import'), ['file' => $csv])
            ->assertSessionHas('success');

        $this->assertSame(2, HotspotVoucher::count());
        $this->assertSame('Budi Santoso', HotspotVoucher::where('nim', '2101001')->firstOrFail()->student_name);
    }

    public function test_deleting_a_synced_voucher_also_removes_it_from_the_router(): void
    {
        $voucher = HotspotVoucher::create([
            'nim' => '2101001', 'password' => '2101001', 'router_host' => self::HOST,
            'status' => HotspotVoucher::STATUS_SYNCED, 'mikrotik_id' => '*1A',
        ]);

        $this->mock(MikrotikService::class, function (MockInterface $mock) {
            $mock->shouldReceive('deleteHotspotUser')->once()->with(self::HOST, '*1A');
        });

        $this->actingAs(User::factory()->create())
            ->delete(route('hotspot.vouchers.destroy', $voucher->id))
            ->assertSessionHas('success');

        $this->assertSame(0, HotspotVoucher::count());
    }

    /**
     * Daftar mahasiswa gaya BAAK: judul di baris ke-3, urutan kolom acak,
     * ada NIM ganda, satu baris tanpa NIM, dan satu NIM panjang bertipe angka.
     */
    private function studentWorkbook(): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Mahasiswa');
        $sheet->fromArray([
            ['DAFTAR MAHASISWA AKTIF'],
            ['Semester Gasal 2026/2027'],
            ['Nama Mahasiswa', 'Program Studi', 'NIM', 'Password'],
            ['Mahasiswa A', 'Teknik Informatika', '2101001', ''],
            ['Mahasiswa B', 'Sistem Informasi', '2101002', 'rahasia123'],
            ['Mahasiswa A Ganda', 'Teknik Informatika', '2101001', ''],
            ['Tanpa NIM', 'Manajemen', '', ''],
            ['Mahasiswa C', 'Akuntansi', 20220110123456, ''],
        ], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'cims_voucher_') . '.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            'daftar_mahasiswa.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }
}
