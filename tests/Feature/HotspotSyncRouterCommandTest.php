<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\HotspotVoucher;
use App\Services\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class HotspotSyncRouterCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Alamat uji memakai blok dokumentasi RFC 5737, bukan IP router kampus. */
    private const OLD_HOST = '198.51.100.1';

    private const NEW_HOST = '198.51.100.5';

    private const THIRD_HOST = '198.51.100.9';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.hotspot.router_host' => self::NEW_HOST]);
    }

    public function test_it_moves_the_inventory_row_and_voucher_rows_to_the_configured_host(): void
    {
        $device = Device::factory()->create([
            'ip_address' => self::OLD_HOST,
            'username' => 'cims-app',
        ]);
        $voucher = $this->voucherAt(self::OLD_HOST, '2101001');
        // Jejak lease lama tanpa baris inventaris: tidak boleh membuat deteksi
        // alamat lama jadi ambigu, dan tidak boleh ikut dipindah.
        $untouched = $this->voucherAt(self::THIRD_HOST, '2101002');

        $this->mockRouter();

        $this->artisan('hotspot:sync-router')->assertExitCode(0);

        $this->assertSame(self::NEW_HOST, $device->refresh()->ip_address);
        $this->assertSame(self::NEW_HOST, $voucher->refresh()->router_host);
        $this->assertSame('*1A', $voucher->mikrotik_id, 'Voucher yang sudah synced tidak perlu dipush ulang.');
        $this->assertSame(self::THIRD_HOST, $untouched->refresh()->router_host);
    }

    public function test_dry_run_leaves_the_database_untouched(): void
    {
        $device = Device::factory()->create(['ip_address' => self::OLD_HOST]);
        $voucher = $this->voucherAt(self::OLD_HOST, '2101001');

        $this->mockRouter();

        $this->artisan('hotspot:sync-router', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(self::OLD_HOST, $device->refresh()->ip_address);
        $this->assertSame(self::OLD_HOST, $voucher->refresh()->router_host);
    }

    public function test_it_refuses_to_run_without_a_configured_router_host(): void
    {
        config(['services.hotspot.router_host' => null]);

        Device::factory()->create(['ip_address' => self::OLD_HOST]);

        $this->artisan('hotspot:sync-router')->assertExitCode(1);
    }

    public function test_several_old_addresses_require_the_from_option(): void
    {
        // Dua-duanya ada di inventaris, jadi tidak ada yang bisa dipilih sendiri.
        Device::factory()->create(['ip_address' => self::OLD_HOST]);
        Device::factory()->create(['ip_address' => self::THIRD_HOST]);
        $ambiguous = $this->voucherAt(self::OLD_HOST, '2101001');
        $this->voucherAt(self::THIRD_HOST, '2101002');

        $this->artisan('hotspot:sync-router')->assertExitCode(1);
        $this->assertSame(self::OLD_HOST, $ambiguous->refresh()->router_host);

        // Dengan --from ambiguitasnya hilang dan hanya router itu yang dipindah.
        $this->mockRouter();
        $this->artisan('hotspot:sync-router', ['--from' => self::OLD_HOST])->assertExitCode(0);
        $this->assertSame(self::NEW_HOST, $ambiguous->refresh()->router_host);
    }

    public function test_it_does_not_overwrite_a_device_already_using_the_target_address(): void
    {
        $stale = Device::factory()->create(['ip_address' => self::OLD_HOST]);
        $occupant = Device::factory()->create(['ip_address' => self::NEW_HOST]);
        $voucher = $this->voucherAt(self::OLD_HOST, '2101001');

        $this->mockRouter();

        $this->artisan('hotspot:sync-router')->assertExitCode(0);

        $this->assertSame(self::OLD_HOST, $stale->refresh()->ip_address, 'IP perangkat lama tidak boleh dipindah ke alamat yang sudah dipakai.');
        $this->assertSame(self::NEW_HOST, $occupant->refresh()->ip_address);
        $this->assertSame(self::NEW_HOST, $voucher->refresh()->router_host, 'Baris voucher tetap harus ikut alamat di .env.');
    }

    public function test_an_already_synced_setup_still_probes_the_router(): void
    {
        Device::factory()->create(['ip_address' => self::NEW_HOST]);
        $this->voucherAt(self::NEW_HOST, '2101001');

        $this->mock(MikrotikService::class, function (MockInterface $mock) {
            $mock->shouldReceive('testConnection')->once()->with(self::NEW_HOST)
                ->andReturn(['success' => false, 'error' => 'timeout']);
            $mock->shouldReceive('credentialSourceFor')->andReturn(null);
        });

        // Database sudah sinkron, tapi routernya bisu → exit code gagal.
        $this->artisan('hotspot:sync-router')->assertExitCode(1);
    }

    /** Voucher yang sudah dipush, supaya terbukti mikrotik_id-nya tidak hilang. */
    private function voucherAt(string $host, string $nim): HotspotVoucher
    {
        return HotspotVoucher::create([
            'nim' => $nim,
            'password' => $nim,
            'router_host' => $host,
            'status' => HotspotVoucher::STATUS_SYNCED,
            'mikrotik_id' => '*1A',
            'synced_at' => now(),
        ]);
    }

    private function mockRouter(): void
    {
        $this->mock(MikrotikService::class, function (MockInterface $mock) {
            $mock->shouldReceive('testConnection')->andReturn([
                'success' => true, 'identity' => 'uji', 'board' => 'RB951-2n', 'version' => '7.24',
            ]);
            $mock->shouldReceive('credentialSourceFor')->andReturn('kredensial inventaris uji');
        });
    }
}
