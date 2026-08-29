<?php

namespace Tests\Feature;

use App\Models\HotspotVoucher;
use App\Services\PmbVoucherSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sinkronisasi daftar mahasiswa SISKA → voucher hotspot.
 *
 * Yang dijaga di sini: password mengikuti tanggal lahir, mahasiswa tanpa
 * tanggal lahir tetap dapat voucher dengan password NIM, halaman API dirangkai
 * sampai habis, baris lama diperbarui bukan diduplikat, dan data pribadi dari
 * API tidak ikut masuk ke tabel voucher.
 */
class PmbVoucherSyncTest extends TestCase
{
    use RefreshDatabase;

    /** Alamat uji memakai blok dokumentasi RFC 5737, bukan router kampus. */
    private const ROUTER = '198.51.100.5';

    /** Host & token palsu — tidak ada permintaan yang benar-benar keluar. */
    private const API = 'https://siska.test/api/v1/siska/mahasiswa';

    /** Nilai yang tidak boleh menempel di baris voucher. */
    private const NIK = '3210000000000000';

    private const HASH = '$2y$10$contohhashsandisiska';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pmb.url' => self::API,
            'services.pmb.token' => 'token-uji',
            'services.pmb.per_page' => 2,
            'services.pmb.retries' => 1,
            'services.hotspot.password_format' => 'dmY',
            'services.hotspot.router_host' => self::ROUTER,
            'services.hotspot.default_profile' => 'mahasiswa',
        ]);
    }

    public function test_it_walks_every_page_and_builds_pending_vouchers(): void
    {
        $this->fakeApi();

        $report = $this->sync()->run(self::ROUTER);

        $this->assertSame(4, $report['total']);
        $this->assertSame(4, $report['created']);
        $this->assertSame(0, $report['updated']);
        $this->assertSame(4, HotspotVoucher::count());

        // per_page dari config ikut dikirim, dan halaman kedua benar-benar diminta.
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'per_page=2') && str_contains($r->url(), 'page=2'));

        $voucher = HotspotVoucher::where('nim', '2101001')->firstOrFail();
        $this->assertSame(HotspotVoucher::STATUS_PENDING, $voucher->status);
        $this->assertSame(self::ROUTER, $voucher->router_host);
        $this->assertSame('mahasiswa', $voucher->profile, 'Profile bawaan .env harus terpakai.');
        // Nama prodi hanya ada di list_program_studi, jadi kodenya wajib diterjemahkan.
        $this->assertSame('S1 Informatika', $voucher->program);
    }

    public function test_the_password_is_the_birth_date_or_the_nim_when_siska_has_none(): void
    {
        $this->fakeApi();

        $report = $this->sync()->run(self::ROUTER);

        $this->assertSame('30051988', HotspotVoucher::where('nim', '2101001')->value('password'));
        $this->assertSame('01012000', HotspotVoucher::where('nim', '2101002')->value('password'));

        // Dua mahasiswa tanpa tanggal lahir: satu null, satu salah entri tahun ini.
        $this->assertSame('2101003', HotspotVoucher::where('nim', '2101003')->value('password'));
        $this->assertSame('2101004', HotspotVoucher::where('nim', '2101004')->value('password'));

        $this->assertSame(2, $report['by_birth_date']);
        $this->assertSame(2, $report['by_nim']);
        $this->assertSame(['2101003', '2101004'], $report['nim_samples']);
    }

    public function test_no_personal_data_from_the_api_reaches_the_voucher_row(): void
    {
        $this->fakeApi();

        $this->sync()->run(self::ROUTER);

        $stored = json_encode(HotspotVoucher::all()->map->getAttributes());

        $this->assertStringNotContainsString(self::NIK, $stored);
        $this->assertStringNotContainsString(self::HASH, $stored);
        $this->assertStringNotContainsString('Jalan Contoh', $stored);
    }

    public function test_running_it_again_updates_instead_of_duplicating(): void
    {
        $this->fakeApi();
        $this->sync()->run(self::ROUTER);

        $this->fakeApi();
        $report = $this->sync()->run(self::ROUTER);

        $this->assertSame(0, $report['created']);
        $this->assertSame(4, $report['updated']);
        $this->assertSame(4, HotspotVoucher::count());
    }

    /**
     * Tanggal lahir yang akhirnya diisi di SISKA mengubah password, dan voucher
     * yang sudah ada di router harus dikirim ulang supaya keduanya tidak beda.
     */
    public function test_a_filled_in_birth_date_sends_the_voucher_back_to_pending(): void
    {
        $voucher = HotspotVoucher::create([
            'nim' => '2101003',
            'password' => '2101003',
            'router_host' => self::ROUTER,
            'status' => HotspotVoucher::STATUS_SYNCED,
            'mikrotik_id' => '*1A',
            'last_error' => 'gagal push sebelumnya',
            'synced_at' => now(),
        ]);

        $this->fakeApi(birthDateFor2101003: '1999-12-31');
        $this->sync()->run(self::ROUTER);

        $voucher->refresh();
        $this->assertSame('31121999', $voucher->password);
        $this->assertSame(HotspotVoucher::STATUS_PENDING, $voucher->status);
        $this->assertNull($voucher->last_error);
        $this->assertSame('*1A', $voucher->mikrotik_id, 'Entri di router masih yang sama, hanya perlu dipush ulang.');
    }

    public function test_an_unchanged_voucher_keeps_its_synced_status(): void
    {
        $this->fakeApi();
        $this->sync()->run(self::ROUTER);

        HotspotVoucher::query()->update(['status' => HotspotVoucher::STATUS_SYNCED]);

        $this->fakeApi();
        $this->sync()->run(self::ROUTER);

        $this->assertSame(0, HotspotVoucher::where('status', HotspotVoucher::STATUS_PENDING)->count());
    }

    public function test_dry_run_counts_without_writing(): void
    {
        $this->fakeApi();

        $report = $this->sync()->run(self::ROUTER, ['dry_run' => true]);

        $this->assertSame(4, $report['created']);
        $this->assertSame(2, $report['by_nim']);
        $this->assertSame(0, HotspotVoucher::count());
    }

    public function test_dry_run_tells_new_rows_apart_from_existing_ones(): void
    {
        HotspotVoucher::create([
            'nim' => '2101001',
            'password' => 'lama',
            'router_host' => self::ROUTER,
            'status' => HotspotVoucher::STATUS_SYNCED,
        ]);

        $this->fakeApi();

        $report = $this->sync()->run(self::ROUTER, ['dry_run' => true]);

        $this->assertSame(3, $report['created']);
        $this->assertSame(1, $report['updated']);
        $this->assertSame('lama', HotspotVoucher::where('nim', '2101001')->value('password'));
    }

    public function test_a_rejected_token_says_which_env_key_to_check(): void
    {
        Http::fake([self::API . '*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PMB_API_TOKEN');

        $this->sync()->run(self::ROUTER);
    }

    public function test_it_refuses_to_guess_the_api_address(): void
    {
        config(['services.pmb.url' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('API_PMB');

        $this->sync()->run(self::ROUTER);
    }

    public function test_an_envelope_that_reports_failure_is_not_treated_as_data(): void
    {
        Http::fake([self::API . '*' => Http::response([
            'success' => false,
            'message' => 'Token kedaluwarsa',
            'data' => [],
        ])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Token kedaluwarsa');

        $this->sync()->run(self::ROUTER);
    }

    private function sync(): PmbVoucherSync
    {
        return app(PmbVoucherSync::class);
    }

    /**
     * Dua halaman berisi empat mahasiswa: dua bertanggal lahir, satu null, satu
     * bertahun berjalan (salah entri yang harus jatuh ke password NIM).
     */
    private function fakeApi(?string $birthDateFor2101003 = null): void
    {
        $pages = [
            1 => [
                $this->student('2101001', '1988-05-30'),
                $this->student('2101002', '2000-01-01', 4),
            ],
            2 => [
                $this->student('2101003', $birthDateFor2101003),
                $this->student('2101004', now()->format('Y') . '-03-15'),
            ],
        ];

        Http::fake(function (Request $request) use ($pages) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = max(1, (int) ($query['page'] ?? 1));

            return Http::response($this->envelope($pages[$page] ?? [], $page < count($pages)));
        });
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function envelope(array $rows, bool $hasMore): array
    {
        return [
            'success' => true,
            'message' => 'Data mahasiswa',
            'data' => $rows,
            'meta' => [
                'total' => 4,
                'count' => count($rows),
                'per_page' => 2,
                'total_pages' => 2,
                'has_more_pages' => $hasMore,
            ],
            'list_program_studi' => [
                ['id' => 18, 'nama_program_studi' => 'S1 Informatika'],
                ['id' => 4, 'nama_program_studi' => 'D3 Sistem Informasi'],
            ],
        ];
    }

    /**
     * Satu baris seperti yang dikirim API, lengkap dengan kolom pribadi yang
     * memang ada di jawabannya dan tidak boleh ikut tersimpan.
     *
     * @return array<string,mixed>
     */
    private function student(string $nim, ?string $birthDate, int $prodi = 18): array
    {
        return [
            'nim' => $nim,
            'nama_mahasiswa' => 'Mahasiswa ' . $nim,
            'program_studi_kode' => $prodi,
            'tanggal_lahir' => $birthDate,
            'tempat_lahir' => $birthDate === null ? null : 'Kota Uji',
            'nik' => self::NIK,
            'sandi' => self::HASH,
            'alamat' => 'Jalan Contoh 1',
            'telepon' => '08000000000',
        ];
    }
}
