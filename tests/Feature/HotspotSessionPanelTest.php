<?php

namespace Tests\Feature;

use App\Models\HotspotVoucher;
use App\Models\User;
use App\Services\MikrotikService;
use App\Services\RadiusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use Tests\Concerns\InteractsWithRadius;
use Tests\TestCase;

/**
 * Panel "Sedang Online" membaca dua sumber sekaligus, dan test ini menjaga
 * pembagian wewenang di antara keduanya.
 *
 * radacct di RADIUS tahu semua router — itu satu-satunya tempat sesi bersamaan
 * lintas router terlihat, dan angkanya persis yang akan dipakai FreeRADIUS bila
 * Simultaneous-Use dipasang. Router hanya tahu sesinya sendiri, tapi ia tidak
 * punya baris yatim.
 *
 * Yang paling mahal kalau salah ada tiga:
 *
 *   1. Sesi yang sudah ditutup ikut terhitung. Operator akan mengira mahasiswa
 *      memakai akunnya bersama-sama padahal login-nya berurutan.
 *   2. Sesi basi disamakan dengan sesi hidup. Baris yatim itulah yang akan
 *      menolak login mahasiswa begitu batas sesi dinyalakan, jadi ia harus
 *      terbaca sebelum batasnya dipasang — bukan sesudah ada yang terkunci.
 *   3. Router mati membuat semua sesi tampak tidak ada di router. Itu tuduhan
 *      palsu terhadap sesi yang sehat, karena yang bisu justru routernya.
 */
class HotspotSessionPanelTest extends TestCase
{
    use InteractsWithRadius;
    use RefreshDatabase;

    /** Blok dokumentasi RFC 5737, bukan IP router kampus. */
    private const HOST = '198.51.100.1';

    /** Router lain di database RADIUS yang sama — tidak sedang dilihat operator. */
    private const OTHER_NAS = '198.51.100.9';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRadiusDatabase();

        config(['services.hotspot.router_host' => self::HOST]);

        // Waktu dibekukan karena seluruh arti "basi" adalah selisih jam. sqlite
        // tidak punya now(), jadi RadiusService jatuh ke Carbon::now() — dan itu
        // yang membuat pembekuan ini cukup untuk mengatur umur sesi.
        Carbon::setTestNow('2026-09-04 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_only_open_sessions_are_counted(): void
    {
        $this->seedRadiusSession('2101001');
        $this->seedRadiusSession('2101001', startedMinutesAgo: 300, silentMinutesAgo: 240, closed: true);

        $sessions = app(RadiusService::class)->activeSessions();

        $this->assertSame(1, $sessions['total']);
        $this->assertSame([], $sessions['shared'],
            'Sesi lama yang sudah ditutup tidak boleh terbaca sebagai pemakaian bersamaan.');
    }

    public function test_stale_sessions_are_counted_and_flagged_per_row(): void
    {
        $this->seedRadiusSession('2101001', silentMinutesAgo: 2);
        $this->seedRadiusSession('2101002', startedMinutesAgo: 120, silentMinutesAgo: 45);
        // Tanpa Acct-Interim-Interval baris ini tidak pernah melapor, jadi
        // umurnya sendirilah yang menjadi ukuran.
        $this->seedRadiusSession('2101003', startedMinutesAgo: 90, silentMinutesAgo: null);

        $sessions = app(RadiusService::class)->activeSessions();

        $this->assertSame(3, $sessions['total']);
        $this->assertSame(2, $sessions['stale']);
        $this->assertSame(RadiusService::STALE_AFTER_MINUTES, $sessions['stale_after_minutes']);

        $flags = collect($sessions['sessions'])->pluck('stale', 'username')->all();
        $this->assertFalse($flags['2101001']);
        $this->assertTrue($flags['2101002']);
        $this->assertTrue($flags['2101003']);

        $reported = collect($sessions['sessions'])->pluck('reported', 'username')->all();
        $this->assertFalse($reported['2101003'],
            'Sesi yang belum pernah kirim Interim-Update harus bisa dibedakan dari yang berhenti melapor.');
    }

    /**
     * Sesi tertua-yang-paling-lama-bisu harus tampil lebih dulu.
     *
     * Kalau limit memotong daftarnya, yang hilang harus baris sehat — bukan baris
     * yatim yang justru menjadi alasan panel ini ada.
     */
    public function test_the_longest_silent_session_is_never_hidden_by_the_limit(): void
    {
        $this->seedRadiusSession('2101001', silentMinutesAgo: 1);
        $this->seedRadiusSession('2101002', silentMinutesAgo: 2);
        $this->seedRadiusSession('2101099', startedMinutesAgo: 600, silentMinutesAgo: 500);

        $sessions = app(RadiusService::class)->activeSessions(1);

        $this->assertSame(3, $sessions['total']);
        $this->assertSame(1, $sessions['shown']);
        $this->assertTrue($sessions['truncated']);
        $this->assertSame('2101099', $sessions['sessions'][0]['username']);
    }

    public function test_one_account_open_on_two_routers_is_reported_as_shared(): void
    {
        $this->seedRadiusSession('2101001', overrides: ['nasipaddress' => self::HOST]);
        $this->seedRadiusSession('2101001', overrides: ['nasipaddress' => self::OTHER_NAS]);
        $this->seedRadiusSession('2101002');

        $sessions = app(RadiusService::class)->activeSessions();

        $this->assertSame([['username' => '2101001', 'sessions' => 2]], $sessions['shared'],
            'Pemakaian bersamaan lintas router hanya terlihat dari RADIUS, bukan dari satu router.');
    }

    public function test_endpoint_joins_student_names_and_confirms_sessions_against_the_router(): void
    {
        HotspotVoucher::create([
            'nim' => '2101001',
            'student_name' => 'Rina Kartika',
            'password' => 'rahasia',
            'router_host' => self::HOST,
            'status' => HotspotVoucher::STATUS_SYNCED,
        ]);

        // Ada di RADIUS dan ada di router.
        $this->seedRadiusSession('2101001', overrides: [
            'nasipaddress' => self::HOST,
            'callingstationid' => 'AA:BB:CC:00:00:01',
        ]);
        // Ada di RADIUS, tidak ada di router — sesi yatim.
        $this->seedRadiusSession('2101002', overrides: [
            'nasipaddress' => self::HOST,
            'callingstationid' => 'AA:BB:CC:00:00:02',
        ]);
        // Milik router lain: routernya tidak berwenang menyangkalnya.
        $this->seedRadiusSession('2101003', overrides: [
            'nasipaddress' => self::OTHER_NAS,
            'callingstationid' => 'AA:BB:CC:00:00:03',
        ]);

        $this->mockRouter([[
            'user' => '2101001',
            'address' => '10.5.50.2',
            // Format MAC RouterOS beda dari yang ditulis NAS ke radacct, jadi
            // pencocokannya harus tahan perbedaan itu.
            'mac' => 'aa-bb-cc-00-00-01',
            'uptime' => '30m',
            'bytes_in' => 1024,
            'bytes_out' => 4096,
        ]]);

        $response = $this->actingAs(User::factory()->create())
            ->getJson(route('hotspot.vouchers.active', ['host' => self::HOST]))
            ->assertOk();

        $response->assertJsonPath('router_ok', true)
            ->assertJsonPath('radius.total', 3)
            ->assertJsonPath('router.total', 1)
            ->assertJsonPath('router.sessions.0.student_name', 'Rina Kartika');

        $rows = collect($response->json('radius.sessions'))->keyBy('username');

        $this->assertSame('Rina Kartika', $rows['2101001']['student_name']);
        $this->assertTrue($rows['2101001']['on_router']);

        $this->assertFalse($rows['2101002']['registered'],
            'NIM tanpa voucher CIMS harus ditandai, bukan dibiarkan tampak terdaftar.');
        $this->assertFalse($rows['2101002']['on_router']);

        $this->assertNull($rows['2101003']['on_router'],
            'Router terpilih tidak boleh menyangkal sesi milik NAS lain.');
    }

    /**
     * Router mati tidak boleh berubah menjadi tuduhan.
     *
     * getHotspotActive() mengembalikan array kosong baik saat router sepi maupun
     * saat router mati. Kalau keduanya disamakan, satu router yang sedang tidak
     * bisa dihubungi akan menandai seluruh sesi sehat sebagai tidak ada.
     */
    public function test_unreachable_router_leaves_confirmation_unknown_instead_of_false(): void
    {
        // Sengaja di router yang sedang dilihat operator: kalau NAS-nya berbeda,
        // hasil null bisa datang dari sebab lain dan test ini tidak lagi menguji
        // penjagaan "router mati".
        $this->seedRadiusSession('2101001', overrides: ['nasipaddress' => self::HOST]);

        $this->mock(MikrotikService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getHotspotActive')->andReturn([]);
            $mock->shouldReceive('testConnection')->andReturn(['success' => false, 'error' => 'timeout']);
        });

        $response = $this->actingAs(User::factory()->create())
            ->getJson(route('hotspot.vouchers.active', ['host' => self::HOST]))
            ->assertOk();

        $response->assertJsonPath('router_ok', false)
            ->assertJsonPath('radius.sessions.0.on_router', null);
    }

    public function test_radius_down_reports_an_error_without_breaking_the_panel(): void
    {
        $this->breakRadiusConnection();
        $this->mockRouter([]);

        $response = $this->actingAs(User::factory()->create())
            ->getJson(route('hotspot.vouchers.active', ['host' => self::HOST]))
            ->assertOk();

        $this->assertNotNull($response->json('radius.error'));
        $this->assertSame(0, $response->json('radius.total'));
        $this->assertSame([], $response->json('radius.sessions'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $active
     */
    private function mockRouter(array $active): void
    {
        $this->mock(MikrotikService::class, function (MockInterface $mock) use ($active) {
            $mock->shouldReceive('getHotspotActive')->andReturn($active);
            $mock->shouldReceive('testConnection')->andReturn([
                'success' => true,
                'identity' => 'router-uji',
                'board' => 'CCR',
                'version' => '7.15',
            ]);
        });
    }
}
