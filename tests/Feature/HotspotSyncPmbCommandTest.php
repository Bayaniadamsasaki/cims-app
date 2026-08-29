<?php

namespace Tests\Feature;

use App\Models\HotspotVoucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `hotspot:sync-pmb` adalah jalur tanpa layar untuk menarik mahasiswa dari
 * SISKA. Yang diuji di sini perilaku perintahnya: router tujuan diambil dari
 * .env, kegagalan API tidak berakhir sebagai exception mentah, dan laporannya
 * menyebutkan siapa yang passwordnya memakai NIM.
 */
class HotspotSyncPmbCommandTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTER = '198.51.100.5';

    private const API = 'https://siska.test/api/v1/siska/mahasiswa';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pmb.url' => self::API,
            'services.pmb.token' => 'token-uji',
            'services.pmb.per_page' => 200,
            'services.pmb.retries' => 1,
            'services.hotspot.password_format' => 'dmY',
            'services.hotspot.router_host' => self::ROUTER,
        ]);
    }

    public function test_it_syncs_to_the_router_named_in_env_and_reports_both_password_rules(): void
    {
        $this->fakeApi();

        $this->artisan('hotspot:sync-pmb')
            ->expectsOutputToContain(self::ROUTER)
            ->expectsOutputToContain('2101002')
            ->assertExitCode(0);

        $this->assertSame(2, HotspotVoucher::where('router_host', self::ROUTER)->count());
        $this->assertSame('30051988', HotspotVoucher::where('nim', '2101001')->value('password'));
        $this->assertSame('2101002', HotspotVoucher::where('nim', '2101002')->value('password'));
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->fakeApi();

        $this->artisan('hotspot:sync-pmb', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertSame(0, HotspotVoucher::count());
    }

    public function test_limit_stops_after_the_first_students(): void
    {
        $this->fakeApi();

        $this->artisan('hotspot:sync-pmb', ['--limit' => 1])->assertExitCode(0);

        $this->assertSame(1, HotspotVoucher::count());
    }

    public function test_the_prodi_filter_is_passed_on_to_the_api(): void
    {
        $this->fakeApi();

        $this->artisan('hotspot:sync-pmb', ['--prodi' => '18'])->assertExitCode(0);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'program_studi_kode=18'));
    }

    public function test_it_refuses_to_run_without_a_router_address(): void
    {
        config(['services.hotspot.router_host' => null, 'services.mikrotik.host' => null]);

        $this->artisan('hotspot:sync-pmb')
            ->expectsOutputToContain('HOTSPOT_ROUTER_HOST')
            ->assertExitCode(1);
    }

    public function test_an_api_failure_is_reported_instead_of_thrown(): void
    {
        Http::fake([self::API . '*' => Http::response(['message' => 'Server sibuk'], 500)]);

        $this->artisan('hotspot:sync-pmb')
            ->expectsOutputToContain('HTTP 500')
            ->assertExitCode(1);

        $this->assertSame(0, HotspotVoucher::count());
    }

    public function test_an_empty_result_is_not_reported_as_success(): void
    {
        Http::fake([self::API . '*' => Http::response([
            'success' => true,
            'data' => [],
            'meta' => ['has_more_pages' => false],
            'list_program_studi' => [],
        ])]);

        $this->artisan('hotspot:sync-pmb')->assertExitCode(1);
    }

    /** Satu halaman: satu mahasiswa bertanggal lahir, satu tanpa. */
    private function fakeApi(): void
    {
        Http::fake([self::API . '*' => Http::response([
            'success' => true,
            'data' => [
                [
                    'nim' => '2101001',
                    'nama_mahasiswa' => 'Mahasiswa Satu',
                    'program_studi_kode' => 18,
                    'tanggal_lahir' => '1988-05-30',
                ],
                [
                    'nim' => '2101002',
                    'nama_mahasiswa' => 'Mahasiswa Dua',
                    'program_studi_kode' => 18,
                    'tanggal_lahir' => null,
                ],
            ],
            'meta' => ['total' => 2, 'count' => 2, 'per_page' => 200, 'has_more_pages' => false],
            'list_program_studi' => [['id' => 18, 'nama_program_studi' => 'S1 Informatika']],
        ])]);
    }
}
