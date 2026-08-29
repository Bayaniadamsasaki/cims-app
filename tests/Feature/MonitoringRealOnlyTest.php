<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\DeviceMetric;
use App\Models\MonitoringLog;
use App\Models\OperatingSystem;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AlertService;
use App\Services\MikrotikService;
use App\Services\MonitoringService;
use App\Services\PingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Monitoring wajib melaporkan hasil pengukuran nyata: tidak ada mode simulasi,
 * tidak ada metrik acak, dan alamat privat RFC1918 bukan penanda simulasi.
 *
 * PingService dan MikrotikService di-mock HANYA di dalam test otomatis ini supaya
 * suite tidak menembak jaringan kampus sungguhan. Yang diuji justru apakah kode
 * produksi benar-benar menempuh jalur jaringan itu lalu menyimpan hasilnya apa
 * adanya — begitu ada nilai karangan atau cabang simulasi di produksi, assertion
 * di bawah ini gagal.
 */
class MonitoringRealOnlyTest extends TestCase
{
    use RefreshDatabase;

    /** Balasan ICMP nyata dari perangkat yang hidup. */
    private const PING_REPLY = ['online' => true, 'latency' => 4, 'packet_loss' => 0, 'error' => null];

    /** Tidak ada balasan ICMP sama sekali. */
    private const PING_TIMEOUT = ['online' => false, 'latency' => null, 'packet_loss' => 100, 'error' => null];

    /** ICMP tidak bisa dijalankan dari server ini. */
    private const PING_BROKEN = [
        'online' => false,
        'latency' => null,
        'packet_loss' => null,
        'error' => 'ICMP tidak dapat dijalankan: fungsi exec() dinonaktifkan pada server ini.',
    ];

    /** Satu pembacaan RouterOS yang berhasil; angkanya tetap sama tiap siklus. */
    private const ROUTEROS_READ = [
        'cpu' => 7,
        'ram' => 41,
        'storage' => 12,
        'temp' => 38,
        'uptime' => 987654,
        'rx' => 1234567,
        'tx' => 7654321,
        'interfaces' => [['name' => 'ether1', 'status' => 'up']],
    ];

    /** Alamat yang benar-benar dicek lewat ICMP selama satu test. */
    private array $pinged = [];

    /** Alert yang benar-benar dipicu selama satu test. */
    private array $alerts = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Kanal alert (Telegram/e-mail/webhook) dicatat, bukan dikirim sungguhan.
        $this->mock(AlertService::class, function (MockInterface $mock) {
            $mock->shouldReceive('dispatchAlert')->andReturnUsing(
                function (string $device, string $type, string $message) {
                    $this->alerts[] = ['device' => $device, 'type' => $type, 'message' => $message];
                }
            );
        });
    }

    // ------------------------------------------------------------------
    // 1. Alamat privat RFC1918 tetap dimonitor sungguhan
    // ------------------------------------------------------------------

    /** @return array<string,array<int,string>> */
    public static function monitoringAddresses(): array
    {
        return [
            'blok 10/8' => ['10.0.0.1'],
            'blok 10/8 core kampus' => ['10.10.10.1'],
            'blok 172.16/12' => ['172.16.1.1'],
            'blok 172.16/12 sisi 172.20' => ['172.20.10.1'],
            'blok 192.168/16' => ['192.168.1.1'],
            'alamat publik' => ['103.10.20.30'],
        ];
    }

    #[DataProvider('monitoringAddresses')]
    public function test_every_address_including_rfc1918_is_checked_over_the_real_network(string $ip): void
    {
        $device = $this->mikrotikDevice($ip);
        $this->pingReturns(self::PING_REPLY);
        $this->routerOsReturns(self::ROUTEROS_READ);

        $metric = app(MonitoringService::class)->scanDevice($device);

        $this->assertSame([$ip], $this->pinged, "Alamat {$ip} harus dicek lewat ICMP sungguhan, bukan disimulasikan.");
        $this->assertSame(MonitoringService::STATUS_ONLINE, $metric->last_ping_status);
        $this->assertSame(7, (int) $device->metrics()->first()->last_cpu_usage_percent);
    }

    // ------------------------------------------------------------------
    // 2. Perangkat terjangkau: yang tersimpan = yang terukur
    // ------------------------------------------------------------------

    public function test_a_reachable_device_stores_exactly_what_the_protocol_reported(): void
    {
        $device = $this->mikrotikDevice('10.10.10.1');
        $this->pingReturns(self::PING_REPLY);
        $this->mock(MikrotikService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getSystemMetrics')->once()->with('10.10.10.1')->andReturn(self::ROUTEROS_READ);
            $mock->shouldReceive('lastErrorFor')->andReturn(null);
        });

        app(MonitoringService::class)->scanDevice($device);

        $stored = $device->metrics()->first();
        $this->assertSame(MonitoringService::STATUS_ONLINE, $stored->last_ping_status);
        $this->assertSame(4, (int) $stored->last_ping_latency_ms);
        $this->assertSame(0, (int) $stored->last_packet_loss_percent);
        $this->assertSame(7, (int) $stored->last_cpu_usage_percent);
        $this->assertSame(41, (int) $stored->last_ram_usage_percent);
        $this->assertSame(12, (int) $stored->last_storage_usage_percent);
        $this->assertSame(38, (int) $stored->last_temperature_celsius);
        $this->assertSame(987654, (int) $stored->last_uptime_seconds);
        $this->assertSame(1234567, (int) $stored->last_bandwidth_rx_bps);
        $this->assertSame(7654321, (int) $stored->last_bandwidth_tx_bps);
        $this->assertSame([['name' => 'ether1', 'status' => 'up']], $stored->last_interface_status);
        $this->assertSame('active', $device->fresh()->status);
        $this->assertSame([], $this->alerts, 'Perangkat sehat tidak boleh memicu alert.');
    }

    public function test_monitoring_history_records_the_numbers_that_were_measured(): void
    {
        $device = $this->mikrotikDevice('192.168.1.1');
        $this->pingReturns(self::PING_REPLY);
        $this->routerOsReturns(self::ROUTEROS_READ);

        app(MonitoringService::class)->scanDevice($device);

        $log = MonitoringLog::where('device_id', $device->id)->sole();
        $this->assertSame(MonitoringService::STATUS_ONLINE, $log->status);
        $this->assertSame(4, (int) $log->ping_latency_ms);
        $this->assertSame(7, (int) $log->cpu_usage_percent);
        $this->assertSame(41, (int) $log->ram_usage_percent);
        $this->assertSame(987654, (int) $log->uptime_seconds);
        $this->assertSame(1234567, (int) $log->bandwidth_rx_bps);
    }

    // ------------------------------------------------------------------
    // 3. Tidak ada angka acak
    // ------------------------------------------------------------------

    public function test_identical_readings_never_drift_between_scans_or_between_devices(): void
    {
        $first = $this->mikrotikDevice('10.10.10.1');
        $second = $this->mikrotikDevice('10.10.10.2');
        $this->pingReturns(self::PING_REPLY);
        $this->routerOsReturns(self::ROUTEROS_READ);

        $monitoring = app(MonitoringService::class);
        $monitoring->scanDevice($first);
        $monitoring->scanDevice($first);
        $monitoring->scanDevice($first);
        $monitoring->scanDevice($second);

        $columns = [
            'status', 'ping_latency_ms', 'packet_loss_percent', 'cpu_usage_percent',
            'ram_usage_percent', 'storage_usage_percent', 'temperature_celsius',
            'uptime_seconds', 'bandwidth_rx_bps', 'bandwidth_tx_bps',
        ];

        $measured = MonitoringLog::query()->get($columns)
            ->map(fn ($row) => $row->only($columns))
            ->unique()
            ->values();

        $this->assertCount(4, MonitoringLog::all());
        $this->assertCount(
            1,
            $measured,
            'Pembacaan yang sama harus menghasilkan angka yang sama; nilai yang bergeser berarti ada generator acak.'
        );
    }

    public function test_an_api_that_returns_nothing_does_not_produce_invented_metrics(): void
    {
        $device = $this->mikrotikDevice('172.16.1.1');
        $this->pingReturns(self::PING_REPLY);
        $this->routerOsReturns([
            'cpu' => null, 'ram' => null, 'storage' => null, 'temp' => null,
            'uptime' => null, 'rx' => null, 'tx' => null, 'interfaces' => [],
        ]);

        app(MonitoringService::class)->scanDevice($device);

        $stored = $device->metrics()->first();
        $this->assertSame(MonitoringService::STATUS_DEGRADED, $stored->last_ping_status);
        $this->assertNull($stored->last_cpu_usage_percent);
        $this->assertNull($stored->last_ram_usage_percent);
        $this->assertNull($stored->last_uptime_seconds);
        $this->assertNull($stored->last_bandwidth_rx_bps);
        $this->assertNull($stored->last_interface_status);
        $this->assertSame(4, (int) $stored->last_ping_latency_ms, 'Latensi ICMP yang benar-benar terukur tetap dicatat.');
        $this->assertSame('MONITORING_ERROR', $this->alerts[0]['type'] ?? null);
    }

    // ------------------------------------------------------------------
    // 4. Perangkat tidak terjangkau ditandai apa adanya
    // ------------------------------------------------------------------

    public function test_a_device_that_never_answers_icmp_is_marked_unreachable(): void
    {
        $device = $this->mikrotikDevice('10.20.30.40');
        $this->pingReturns(self::PING_TIMEOUT);
        $this->mock(MikrotikService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('getSystemMetrics');
        });

        $metric = app(MonitoringService::class)->scanDevice($device);

        $this->assertSame(MonitoringService::STATUS_UNREACHABLE, $metric->last_ping_status);
        $this->assertNull($metric->last_ping_latency_ms);
        $this->assertSame(100, (int) $metric->last_packet_loss_percent);
        $this->assertSame('offline', $device->fresh()->status);
        $this->assertSame(
            MonitoringService::STATUS_UNREACHABLE,
            MonitoringLog::where('device_id', $device->id)->sole()->status
        );
        $this->assertSame('CRITICAL_OFFLINE', $this->alerts[0]['type'] ?? null);
        $this->assertStringContainsString('10.20.30.40', $this->alerts[0]['message'] ?? '');
    }

    public function test_an_icmp_failure_on_the_server_is_reported_as_a_monitoring_error(): void
    {
        $device = $this->mikrotikDevice('192.168.10.1');
        $this->pingReturns(self::PING_BROKEN);

        $metric = app(MonitoringService::class)->scanDevice($device);

        $this->assertSame(MonitoringService::STATUS_ERROR, $metric->last_ping_status);
        $this->assertSame(
            'active',
            $device->fresh()->status,
            'Kegagalan di sisi server bukan bukti perangkatnya mati, jadi status inventaris tidak diubah.'
        );
        $this->assertSame('MONITORING_ERROR', $this->alerts[0]['type'] ?? null);
        $this->assertStringContainsString('exec()', $this->alerts[0]['message'] ?? '');
    }

    // ------------------------------------------------------------------
    // 5. Metrik valid terakhir tidak pernah ditimpa data karangan
    // ------------------------------------------------------------------

    public function test_existing_metrics_survive_a_failed_scan_without_being_replaced(): void
    {
        $device = $this->mikrotikDevice('172.16.1.1');
        $this->seedLastKnownGoodMetrics($device);
        $this->pingReturns(self::PING_TIMEOUT);

        app(MonitoringService::class)->scanDevice($device);

        $stored = $device->metrics()->first();
        $this->assertSame(MonitoringService::STATUS_UNREACHABLE, $stored->last_ping_status);
        $this->assertSame(12, (int) $stored->last_cpu_usage_percent);
        $this->assertSame(34, (int) $stored->last_ram_usage_percent);
        $this->assertSame(56, (int) $stored->last_storage_usage_percent);
        $this->assertSame(424242, (int) $stored->last_uptime_seconds);
        $this->assertSame([['name' => 'ether1', 'status' => 'up']], $stored->last_interface_status);
        $this->assertNull($stored->last_ping_latency_ms, 'Latensi lama tidak boleh dipakai ulang seolah baru terukur.');
    }

    public function test_a_reachable_device_with_a_failing_api_is_degraded_and_keeps_its_last_valid_metrics(): void
    {
        $device = $this->mikrotikDevice('10.10.10.1');
        $this->seedLastKnownGoodMetrics($device);
        $this->pingReturns(self::PING_REPLY);
        $this->routerOsReturns(
            [
                'cpu' => null, 'ram' => null, 'storage' => null, 'temp' => null,
                'uptime' => null, 'rx' => null, 'tx' => null, 'interfaces' => [],
            ],
            'gagal terhubung ke 10.10.10.1: Unable to establish socket session.'
        );

        app(MonitoringService::class)->scanDevice($device);

        $stored = $device->metrics()->first();
        $this->assertSame(MonitoringService::STATUS_DEGRADED, $stored->last_ping_status);
        $this->assertSame(12, (int) $stored->last_cpu_usage_percent);
        $this->assertSame(424242, (int) $stored->last_uptime_seconds);
        $this->assertSame(4, (int) $stored->last_ping_latency_ms);
        $this->assertNull(
            MonitoringLog::where('device_id', $device->id)->latest('id')->first()->cpu_usage_percent,
            'Riwayat hanya mencatat yang benar-benar terukur pada siklus itu.'
        );
        $this->assertStringContainsString('RouterOS API', $this->alerts[0]['message'] ?? '');
    }

    // ------------------------------------------------------------------
    // 6. Alamat monitoring bermasalah = error konfigurasi, bukan simulasi
    // ------------------------------------------------------------------

    public function test_a_device_without_an_ip_address_is_a_monitoring_error_and_is_never_pinged(): void
    {
        $device = $this->mikrotikDevice(null);
        $this->mock(PingService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('check'));

        $metric = app(MonitoringService::class)->scanDevice($device);

        $this->assertSame(MonitoringService::STATUS_ERROR, $metric->last_ping_status);
        $this->assertNull($metric->last_cpu_usage_percent);
        $this->assertSame('MONITORING_CONFIG_ERROR', $this->alerts[0]['type'] ?? null);
        $this->assertStringContainsString('belum diisi', $this->alerts[0]['message'] ?? '');
    }

    public function test_an_unparsable_monitoring_address_is_reported_as_a_configuration_error(): void
    {
        $device = $this->mikrotikDevice('router-lantai-3');
        $this->mock(PingService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('check'));

        $metric = app(MonitoringService::class)->scanDevice($device);

        $this->assertSame(MonitoringService::STATUS_ERROR, $metric->last_ping_status);
        $this->assertStringContainsString('router-lantai-3', $this->alerts[0]['message'] ?? '');
    }

    public function test_a_management_port_in_the_address_is_stripped_before_the_real_icmp_check(): void
    {
        $device = $this->mikrotikDevice('192.168.1.254:8729');
        $this->pingReturns(self::PING_REPLY);
        $this->mock(MikrotikService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getSystemMetrics')->once()->with('192.168.1.254:8729')->andReturn(self::ROUTEROS_READ);
            $mock->shouldReceive('lastErrorFor')->andReturn(null);
        });

        $metric = app(MonitoringService::class)->scanDevice($device);

        $this->assertSame(
            ['192.168.1.254'],
            $this->pinged,
            'ICMP dikirim ke host-nya, sedangkan port RouterOS API tetap dipakai untuk API.'
        );
        $this->assertSame(MonitoringService::STATUS_ONLINE, $metric->last_ping_status);
    }

    public function test_a_manually_maintained_device_keeps_its_inventory_status(): void
    {
        $device = $this->mikrotikDevice('10.10.10.9');
        $device->update(['status' => 'maintenance']);
        $this->pingReturns(self::PING_TIMEOUT);

        $metric = app(MonitoringService::class)->scanDevice($device);

        $this->assertSame(MonitoringService::STATUS_UNREACHABLE, $metric->last_ping_status);
        $this->assertSame('maintenance', $device->fresh()->status);
    }

    // ------------------------------------------------------------------
    // 7. Discovery gagal tidak boleh melahirkan interface karangan
    // ------------------------------------------------------------------

    public function test_failed_interface_discovery_creates_no_interfaces_and_deletes_none(): void
    {
        $device = $this->mikrotikDevice('172.16.1.1');
        DeviceInterface::factory()->create([
            'device_id' => $device->id,
            'interface_name' => 'ether1',
            'ip_address' => '172.16.1.1',
        ]);

        $this->mock(MikrotikService::class, function (MockInterface $mock) {
            $mock->shouldReceive('syncDeviceInterfaces')
                ->once()
                ->andThrow(new \Exception('Unable to establish socket session.'));
        });

        $this->actingAs(User::factory()->create())
            ->post(route('devices.sync-interfaces', $device->id))
            ->assertRedirect();

        $interfaces = DeviceInterface::where('device_id', $device->id)->get();
        $this->assertCount(1, $interfaces, 'Inventaris interface yang sah tidak dihapus hanya karena satu discovery gagal.');
        $this->assertSame('ether1', $interfaces->first()->interface_name);
        $this->assertStringContainsString('gagal', (string) session('error'));
        $this->assertStringContainsString('tidak diubah', (string) session('error'));
    }

    public function test_interface_sync_is_refused_for_a_device_without_an_ip_address(): void
    {
        $device = $this->mikrotikDevice(null);
        $this->mock(MikrotikService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('syncDeviceInterfaces'));

        $this->actingAs(User::factory()->create())
            ->post(route('devices.sync-interfaces', $device->id))
            ->assertRedirect();

        $this->assertSame(0, DeviceInterface::where('device_id', $device->id)->count());
        $this->assertStringContainsString('IP Address', (string) session('error'));
    }

    // ------------------------------------------------------------------
    // 8. Jalur SNMP juga melaporkan kegagalan nyata
    // ------------------------------------------------------------------

    public function test_a_non_mikrotik_device_reports_the_real_snmp_failure_instead_of_fake_metrics(): void
    {
        if (extension_loaded('snmp')) {
            $this->markTestSkipped('Ekstensi snmp terpasang; jalur kegagalan ini diuji tanpa membangkitkan trafik SNMP nyata.');
        }

        $device = Device::factory()->create([
            'ip_address' => '10.30.0.5',
            'status' => 'active',
            'vendor_id' => Vendor::factory()->create(['name' => 'Cisco'])->id,
            'operating_system_id' => OperatingSystem::factory()->create(['name' => 'IOS-XE', 'vendor' => 'Cisco'])->id,
        ]);
        $this->pingReturns(self::PING_REPLY);

        $metric = app(MonitoringService::class)->scanDevice($device);

        $this->assertSame(MonitoringService::STATUS_DEGRADED, $metric->last_ping_status);
        $this->assertNull($metric->last_cpu_usage_percent);
        $this->assertNull($metric->last_uptime_seconds);
        $this->assertStringContainsString('snmp', strtolower($this->alerts[0]['message'] ?? ''));
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /** Perangkat MikroTik nyata di inventaris, tanpa nilai acak pada kolom yang diuji. */
    private function mikrotikDevice(?string $ip): Device
    {
        return Device::factory()->create([
            'ip_address' => $ip,
            'status' => 'active',
            'vendor_id' => Vendor::factory()->create(['name' => 'MikroTik'])->id,
            'operating_system_id' => OperatingSystem::factory()->create(['name' => 'RouterOS', 'vendor' => 'MikroTik'])->id,
        ]);
    }

    /** @param array{online:bool,latency:?int,packet_loss:?int,error:?string} $result */
    private function pingReturns(array $result): void
    {
        $this->mock(PingService::class, function (MockInterface $mock) use ($result) {
            $mock->shouldReceive('check')->andReturnUsing(function (string $ip, int $timeoutMs = 1000) use ($result) {
                $this->pinged[] = $ip;

                return $result;
            });
        });
    }

    /** @param array<string,mixed> $metrics */
    private function routerOsReturns(array $metrics, ?string $error = null): void
    {
        $this->mock(MikrotikService::class, function (MockInterface $mock) use ($metrics, $error) {
            $mock->shouldReceive('getSystemMetrics')->andReturn($metrics);
            $mock->shouldReceive('lastErrorFor')->andReturn($error);
        });
    }

    /** Metrik valid hasil siklus sebelumnya, yang wajib bertahan saat scan gagal. */
    private function seedLastKnownGoodMetrics(Device $device): void
    {
        DeviceMetric::create([
            'device_id' => $device->id,
            'last_ping_status' => MonitoringService::STATUS_ONLINE,
            'last_ping_latency_ms' => 3,
            'last_packet_loss_percent' => 0,
            'last_cpu_usage_percent' => 12,
            'last_ram_usage_percent' => 34,
            'last_storage_usage_percent' => 56,
            'last_temperature_celsius' => 40,
            'last_uptime_seconds' => 424242,
            'last_interface_status' => [['name' => 'ether1', 'status' => 'up']],
            'last_bandwidth_rx_bps' => 111,
            'last_bandwidth_tx_bps' => 222,
            'last_checked_at' => now()->subMinutes(5),
        ]);
    }
}
