<?php

namespace Tests\Feature;

use App\Models\HotspotVoucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithRadius;
use Tests\TestCase;

/**
 * `hotspot:sync-pmb` adalah jalur tanpa layar untuk menarik mahasiswa dari
 * SISKA. Yang diuji di sini perilaku perintahnya: router tujuan diambil dari
 * .env, kegagalan API tidak berakhir sebagai exception mentah, laporannya
 * menyebutkan siapa yang passwordnya memakai NIM, dan dua opsi yang mengatur
 * penutupan akses — --no-deactivate serta --force — benar-benar tersambung.
 */
class HotspotSyncPmbCommandTest extends TestCase
{
    use InteractsWithRadius;
    use RefreshDatabase;

    private const ROUTER = '198.51.100.5';

    private const API = 'https://siska.test/api/v1/siska/mahasiswa';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRadiusDatabase();

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

    /**
     * Penutupan akses adalah satu-satunya hal yang perintah ini lakukan tanpa
     * ada orang yang menekan tombol, jadi jumlahnya harus terbaca di laporan —
     * bukan cuma terjadi diam-diam di database.
     *
     * Tiga voucher PMB, tarikan hanya membawa dua: 2 dari 3 masih di bawah
     * ambang, jadi pengaman menahan penutupan sampai --force diberikan.
     */
    public function test_the_report_names_the_closings_and_holds_them_until_force_is_given(): void
    {
        $this->pmbVoucher('2101001');
        $this->pmbVoucher('2101002');
        $gone = $this->pmbVoucher('2101003');

        $this->fakeApi();

        $this->artisan('hotspot:sync-pmb')
            ->expectsOutputToContain('Tidak ada lagi di SISKA')
            ->expectsOutputToContain('--force')
            ->assertExitCode(0);

        $this->assertSame(HotspotVoucher::STATUS_SYNCED, $gone->refresh()->status, 'Pengaman justru tidak menahan apa pun.');
        $this->assertSame([], $this->radiusCheck('2101003'));

        $this->artisan('hotspot:sync-pmb', ['--force' => true])
            ->expectsOutputToContain('Di antaranya ditutup sekarang')
            ->assertExitCode(0);

        $gone->refresh();
        $this->assertSame(HotspotVoucher::STATUS_DISABLED, $gone->status);
        $this->assertSame('tidak ada di PMB', $gone->disabled_reason);
        $this->assertSame('Reject', $this->radiusCheck('2101003')['Auth-Type'] ?? null);
    }

    /** Jalur untuk tarikan yang diketahui tidak lengkap: tulis yang ada, jangan tutup apa pun. */
    public function test_no_deactivate_leaves_the_missing_nims_open(): void
    {
        $this->pmbVoucher('2101001');
        $this->pmbVoucher('2101002');
        $gone = $this->pmbVoucher('2101003');

        $this->fakeApi();

        $this->artisan('hotspot:sync-pmb', ['--no-deactivate' => true])->assertExitCode(0);

        $this->assertSame(HotspotVoucher::STATUS_SYNCED, $gone->refresh()->status);
        $this->assertSame([], $this->radiusCheck('2101003'));
    }

    /** Voucher yang sudah pernah dilihat sinkronisasi PMB — hanya ini yang boleh ditutup. */
    private function pmbVoucher(string $nim): HotspotVoucher
    {
        return HotspotVoucher::create([
            'nim' => $nim,
            'password' => 'lama' . $nim,
            'router_host' => self::ROUTER,
            'source' => HotspotVoucher::SOURCE_PMB,
            'status' => HotspotVoucher::STATUS_SYNCED,
        ]);
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
