<?php

namespace Tests\Feature;

use App\Jobs\ScanDeviceJob;
use App\Models\Device;
use App\Models\DeviceMetric;
use App\Models\MonitoringLog;
use App\Models\OperatingSystem;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AlertService;
use App\Services\MikrotikService;
use App\Services\MonitoringService;
use App\Services\PingService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Arsitektur eksekusi monitoring: penjadwalan lewat antrean, satu job per
 * perangkat, dan request HTTP yang tidak pernah menunggu jaringan.
 *
 * PingService, MikrotikService, dan AlertService di-mock HANYA di dalam test
 * otomatis ini supaya suite tidak menembak jaringan kampus sungguhan. Yang diuji
 * justru apakah kode produksi benar-benar menempuh jalur antrean dan jalur
 * jaringan itu, lalu menyimpan hasilnya apa adanya.
 */
class MonitoringQueueTest extends TestCase
{
    use RefreshDatabase;

    /** Balasan ICMP nyata dari perangkat yang hidup. */
    private const PING_REPLY = ['online' => true, 'latency' => 4, 'packet_loss' => 0, 'error' => null];

    /** Tidak ada balasan ICMP sama sekali. */
    private const PING_TIMEOUT = ['online' => false, 'latency' => null, 'packet_loss' => 100, 'error' => null];

    /** Satu pembacaan RouterOS yang berhasil. */
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

    protected function setUp(): void
    {
        parent::setUp();

        // Kanal alert tidak dikirim sungguhan; arsitektur alert diuji terpisah.
        $this->mock(
            AlertService::class,
            fn (MockInterface $mock) => $mock->shouldReceive('dispatchAlert')->andReturnNull()
        );
    }

    // ------------------------------------------------------------------
    // 1. Penjadwalan: dispatch job, bukan pemindaian di dalam pemanggil
    // ------------------------------------------------------------------

    public function test_dispatch_scans_queues_one_job_per_device_without_touching_the_network(): void
    {
        Queue::fake();
        $this->networkMustNotBeTouched();

        $devices = Device::factory()->count(3)->create();

        $dispatched = app(MonitoringService::class)->dispatchScans();

        $this->assertSame(3, $dispatched);
        Queue::assertPushed(ScanDeviceJob::class, 3);
        Queue::assertPushedOn(config('monitoring.queue'), ScanDeviceJob::class);

        foreach ($devices as $device) {
            Queue::assertPushed(
                ScanDeviceJob::class,
                fn (ScanDeviceJob $job) => $job->deviceId === $device->id
            );
        }
    }

    public function test_dispatch_scans_reports_zero_when_the_inventory_is_empty(): void
    {
        Queue::fake();

        $this->assertSame(0, app(MonitoringService::class)->dispatchScans());

        Queue::assertNothingPushed();
    }

    // ------------------------------------------------------------------
    // 2. Tombol "Pindai Sekarang" tidak lagi menunggu seluruh inventaris
    // ------------------------------------------------------------------

    public function test_manual_scan_request_dispatches_jobs_and_returns_immediately(): void
    {
        Queue::fake();
        $this->networkMustNotBeTouched();
        Device::factory()->count(4)->create();

        $this->actingAs(User::factory()->create())
            ->post(route('monitoring.scan'))
            ->assertRedirect();

        Queue::assertPushed(ScanDeviceJob::class, 4);
        $this->assertStringContainsString('4 perangkat', (string) session('success'));
    }

    public function test_manual_scan_request_never_scans_the_inventory_synchronously(): void
    {
        Queue::fake();

        $this->mock(MonitoringService::class, function (MockInterface $mock) {
            $mock->shouldReceive('dispatchScans')->once()->andReturn(2);
            $mock->shouldNotReceive('scanAll');
            $mock->shouldNotReceive('scanDevice');
        });

        $this->actingAs(User::factory()->create())
            ->post(route('monitoring.scan'))
            ->assertRedirect();

        $this->assertStringContainsString('2 perangkat', (string) session('success'));
    }

    public function test_manual_scan_on_an_empty_inventory_says_so_instead_of_claiming_success(): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->post(route('monitoring.scan'))
            ->assertRedirect();

        Queue::assertNothingPushed();
        $this->assertNull(session('success'));
        $this->assertStringContainsString('Tidak ada perangkat', (string) session('error'));
    }

    // ------------------------------------------------------------------
    // 3. Satu perangkat tidak pernah dipindai dua kali bersamaan
    // ------------------------------------------------------------------

    public function test_a_device_already_queued_is_not_scheduled_twice(): void
    {
        Queue::fake();
        $device = Device::factory()->create();

        ScanDeviceJob::dispatch($device->id);
        ScanDeviceJob::dispatch($device->id);

        Queue::assertPushed(ScanDeviceJob::class, 1);
    }

    public function test_the_duplicate_guard_is_per_device_and_never_blocks_other_devices(): void
    {
        Queue::fake();
        $first = Device::factory()->create();
        $second = Device::factory()->create();

        // Dua siklus scheduler beruntun: perangkat yang jobnya masih mengantre
        // tidak dijadwalkan ulang, tetapi tiap perangkat tetap dapat satu job.
        app(MonitoringService::class)->dispatchScans();
        app(MonitoringService::class)->dispatchScans();

        Queue::assertPushed(ScanDeviceJob::class, 2);
        Queue::assertPushed(ScanDeviceJob::class, fn (ScanDeviceJob $job) => $job->deviceId === $first->id);
        Queue::assertPushed(ScanDeviceJob::class, fn (ScanDeviceJob $job) => $job->deviceId === $second->id);
    }

    public function test_the_unique_lock_is_keyed_by_device_and_covers_the_whole_retry_window(): void
    {
        $job = new ScanDeviceJob(77);

        $lifecycle = (int) config('monitoring.job_tries') * (int) config('monitoring.job_timeout')
            + array_sum(config('monitoring.job_backoff'));

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('77', $job->uniqueId());
        $this->assertSame((int) config('monitoring.unique_for'), $job->uniqueFor());
        $this->assertGreaterThan(
            $lifecycle,
            $job->uniqueFor(),
            'Kunci unik harus hidup lebih lama dari seluruh siklus percobaan ulang, '
            .'kalau tidak perangkat yang sama bisa dipindai dua kali bersamaan.'
        );
    }

    // ------------------------------------------------------------------
    // 4. Eksekusi job: metrik terkini, riwayat, dan status nyata tersimpan
    // ------------------------------------------------------------------

    public function test_a_queued_scan_stores_exactly_what_the_device_reported(): void
    {
        $device = $this->mikrotikDevice('10.10.10.1');
        $this->pingReturns(self::PING_REPLY);
        $this->routerOsReturns(self::ROUTEROS_READ);

        ScanDeviceJob::dispatchSync($device->id);

        $stored = $device->metrics()->first();
        $log = MonitoringLog::where('device_id', $device->id)->first();

        $this->assertSame(['10.10.10.1'], $this->pinged);
        $this->assertSame(MonitoringService::STATUS_ONLINE, $stored->last_ping_status);
        $this->assertSame(4, (int) $stored->last_ping_latency_ms);
        $this->assertSame(7, (int) $stored->last_cpu_usage_percent);
        $this->assertSame(41, (int) $stored->last_ram_usage_percent);
        $this->assertSame(987654, (int) $stored->last_uptime_seconds);
        $this->assertSame('active', $device->fresh()->status);

        $this->assertNotNull($log, 'Setiap siklus pindai harus meninggalkan satu baris riwayat.');
        $this->assertSame(MonitoringService::STATUS_ONLINE, $log->status);
        $this->assertSame(7, (int) $log->cpu_usage_percent);
    }

    public function test_a_queued_scan_of_an_unreachable_device_is_recorded_without_failing_the_job(): void
    {
        $device = $this->mikrotikDevice('10.10.10.9');
        $this->seedLastKnownGoodMetrics($device);
        $this->pingReturns(self::PING_TIMEOUT);
        $this->mock(
            MikrotikService::class,
            fn (MockInterface $mock) => $mock->shouldNotReceive('getSystemMetrics')
        );

        ScanDeviceJob::dispatchSync($device->id);

        $stored = $device->metrics()->first();

        $this->assertSame(MonitoringService::STATUS_UNREACHABLE, $stored->last_ping_status);
        $this->assertSame(100, (int) $stored->last_packet_loss_percent);
        $this->assertNull($stored->last_ping_latency_ms);
        $this->assertSame(
            12,
            (int) $stored->last_cpu_usage_percent,
            'Satu kegagalan jangkauan tidak boleh menghapus metrik valid terakhir.'
        );
        $this->assertSame('offline', $device->fresh()->status);
        $this->assertSame(1, MonitoringLog::where('device_id', $device->id)->count());
    }

    public function test_a_device_deleted_after_dispatch_is_skipped_instead_of_failing_forever(): void
    {
        Log::spy();
        $this->networkMustNotBeTouched();

        ScanDeviceJob::dispatchSync(4242);

        $this->assertSame(0, DeviceMetric::count());
        $this->assertSame(0, MonitoringLog::count());

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message) => str_contains($message, '#4242'))
            ->once();
    }

    // ------------------------------------------------------------------
    // 5. Isolasi kegagalan, timeout, dan percobaan ulang
    // ------------------------------------------------------------------

    public function test_one_broken_device_never_stops_the_devices_after_it(): void
    {
        $first = $this->mikrotikDevice('10.20.0.1');
        $broken = $this->mikrotikDevice('10.20.0.2');
        $last = $this->mikrotikDevice('10.20.0.3');

        $this->routerOsReturns(self::ROUTEROS_READ);
        $this->mock(PingService::class, function (MockInterface $mock) {
            $mock->shouldReceive('check')->andReturnUsing(function (string $ip, int $timeoutMs = 1000) {
                $this->pinged[] = $ip;

                if ($ip === '10.20.0.2') {
                    throw new RuntimeException('soket ICMP tidak bisa dibuka untuk alamat ini');
                }

                return self::PING_REPLY;
            });
        });

        $scanned = app(MonitoringService::class)->scanAll();

        $this->assertSame(2, $scanned);
        $this->assertSame(['10.20.0.1', '10.20.0.2', '10.20.0.3'], $this->pinged);
        $this->assertNotNull($first->metrics()->first());
        $this->assertNotNull($last->metrics()->first(), 'Perangkat setelah yang gagal tetap harus dipindai.');
        $this->assertNull($broken->metrics()->first());
    }

    public function test_an_unexpected_failure_bubbles_up_so_the_queue_can_retry_it(): void
    {
        $device = Device::factory()->create();

        $this->mock(MonitoringService::class, fn (MockInterface $mock) => $mock
            ->shouldReceive('scanDevice')
            ->once()
            ->andThrow(new RuntimeException('koneksi terputus di tengah pembacaan')));

        $caught = null;

        try {
            (new ScanDeviceJob($device->id))->handle(app(MonitoringService::class));
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull(
            $caught,
            'Kegagalan tak terduga harus dilempar keluar agar antrean menjadwalkan percobaan ulang.'
        );
        $this->assertSame('koneksi terputus di tengah pembacaan', $caught->getMessage());
    }

    public function test_the_job_carries_timeout_retry_and_backoff_limits_into_the_queue_payload(): void
    {
        $job = new ScanDeviceJob(5);

        $this->assertSame((int) config('monitoring.job_timeout'), $job->timeout);
        $this->assertSame((int) config('monitoring.job_tries'), $job->tries);
        $this->assertSame(config('monitoring.job_backoff'), $job->backoff());
        $this->assertSame(config('monitoring.queue'), $job->queue);
        $this->assertGreaterThan(1, $job->tries, 'Gangguan sesaat harus mendapat percobaan ulang.');
        $this->assertNotEmpty($job->backoff(), 'Percobaan ulang tanpa jeda hanya membebani perangkat yang sakit.');
    }

    public function test_the_scan_timeout_stays_below_the_queue_retry_window(): void
    {
        // Kalau timeout satu pemindaian >= retry_after antrean, worker lain akan
        // mengambil job yang masih hidup dan satu perangkat dipindai dua kali.
        $retryAfter = (int) config('queue.connections.database.retry_after');

        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThan($retryAfter, (int) config('monitoring.job_timeout'));
    }

    public function test_a_permanently_failed_scan_is_logged_with_the_device_it_belongs_to(): void
    {
        Log::spy();

        (new ScanDeviceJob(31))->failed(new RuntimeException('perangkat tidak menjawab sama sekali'));

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, '#31')
                && str_contains($message, 'perangkat tidak menjawab sama sekali'))
            ->once();
    }

    // ------------------------------------------------------------------
    // 6. Perintah terminal dan scheduler
    // ------------------------------------------------------------------

    public function test_the_scheduled_command_only_queues_work_and_never_scans_in_process(): void
    {
        Queue::fake();
        $this->networkMustNotBeTouched();
        Device::factory()->count(2)->create();

        $this->artisan('monitor:scan')
            ->expectsOutputToContain('dijadwalkan untuk 2 perangkat')
            ->assertSuccessful();

        Queue::assertPushed(ScanDeviceJob::class, 2);
    }

    public function test_the_sync_option_still_scans_in_process_without_using_the_queue(): void
    {
        Queue::fake();
        $device = $this->mikrotikDevice('192.168.1.254');
        $this->pingReturns(self::PING_REPLY);
        $this->routerOsReturns(self::ROUTEROS_READ);

        $this->artisan('monitor:scan --sync')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(['192.168.1.254'], $this->pinged);
        $this->assertSame(MonitoringService::STATUS_ONLINE, $device->metrics()->first()->last_ping_status);
    }

    public function test_the_scheduler_queues_the_scan_every_minute_without_overlapping(): void
    {
        // Menjalankan satu perintah artisan membuat callback withSchedule di
        // bootstrap/app.php terdaftar, sehingga daftar event bisa diperiksa.
        $this->artisan('schedule:list')->assertSuccessful();

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'monitor:scan'));

        $this->assertNotNull($event, 'Perintah monitor:scan harus terdaftar di scheduler.');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue(
            $event->withoutOverlapping,
            'Tanpa withoutOverlapping, loop dispatch bisa menumpuk pada inventaris besar.'
        );
        $this->assertStringNotContainsString('--sync', (string) $event->command);
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    /** Tidak satu pun koneksi jaringan boleh dibuka di jalur yang diuji. */
    private function networkMustNotBeTouched(): void
    {
        $this->mock(PingService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('check'));
        $this->mock(
            MikrotikService::class,
            fn (MockInterface $mock) => $mock->shouldNotReceive('getSystemMetrics')
        );
    }

    /** Perangkat MikroTik nyata di inventaris, tanpa nilai acak pada kolom yang diuji. */
    private function mikrotikDevice(?string $ip): Device
    {
        return Device::factory()->create([
            'ip_address' => $ip,
            'status' => 'active',
            'vendor_id' => Vendor::factory()->create(['name' => 'MikroTik'])->id,
            'operating_system_id' => OperatingSystem::factory()->create([
                'name' => 'RouterOS',
                'vendor' => 'MikroTik',
            ])->id,
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

    /** Metrik valid hasil siklus sebelumnya, yang wajib bertahan saat pindai gagal. */
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
