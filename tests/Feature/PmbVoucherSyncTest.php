<?php

namespace Tests\Feature;

use App\Models\HotspotVoucher;
use App\Services\PmbVoucherSync;
use App\Support\VoucherRadiusApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithRadius;
use Tests\TestCase;

/**
 * Sinkronisasi daftar mahasiswa SISKA → voucher hotspot.
 *
 * Yang dijaga di sini: password mengikuti tanggal lahir, mahasiswa tanpa
 * tanggal lahir tetap dapat voucher dengan password NIM, halaman API dirangkai
 * sampai habis, baris lama diperbarui bukan diduplikat, dan data pribadi dari
 * API tidak ikut masuk ke tabel voucher.
 *
 * Sejak voucher bermuara di RADIUS, sinkronisasi juga MENUTUP akses NIM yang
 * hilang dari SISKA — satu keputusan yang memutus WiFi di seluruh kampus, bukan
 * di satu router. Tiga pengamannya (tarikan bersaring, respons kosong, dan
 * penurunan jumlah yang drastis) ikut diuji di sini, karena satu-satunya yang
 * berdiri antara respons API yang terpotong dan ratusan mahasiswa yang tidak
 * bisa login adalah ketiganya.
 */
class PmbVoucherSyncTest extends TestCase
{
    use InteractsWithRadius;
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

        // Penonaktifan menulis 'Auth-Type := Reject' ke RADIUS, jadi tabelnya harus
        // ada. default_group sengaja dibiarkan kosong supaya HOTSPOT_DEFAULT_PROFILE
        // yang lama tetap teruji sebagai fallback.
        $this->setUpRadiusDatabase();

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

    /**
     * Mahasiswa yang hilang dari SISKA ditutup aksesnya, bukan dibiarkan bisa
     * login selamanya. Kredensialnya sengaja tidak dihapus: penolakan cukup satu
     * baris, dan aktivasi kembali cuma menghapus baris itu.
     */
    public function test_a_nim_that_left_siska_is_disabled_and_rejected_in_radius(): void
    {
        $this->fakeStudents($this->nims(10));
        $this->sync()->run(self::ROUTER);
        app(VoucherRadiusApplier::class)->apply(HotspotVoucher::all());

        // Satu mahasiswa hilang dari daftar — 9 dari 10 masih di atas ambang 80%.
        $this->fakeStudents($this->nims(9));
        $report = $this->sync()->run(self::ROUTER);

        $gone = HotspotVoucher::where('nim', '2101010')->sole();
        $this->assertSame(HotspotVoucher::STATUS_DISABLED, $gone->status);
        $this->assertSame('tidak ada di PMB', $gone->disabled_reason);

        $check = $this->radiusCheck('2101010');
        $this->assertSame('Reject', $check['Auth-Type'] ?? null);
        $this->assertSame('30051988', $check['Cleartext-Password'] ?? null);

        // Yang masih terdaftar tidak boleh ikut tersentuh sama sekali.
        $this->assertSame(HotspotVoucher::STATUS_SYNCED, HotspotVoucher::where('nim', '2101009')->value('status'));
        $this->assertArrayNotHasKey('Auth-Type', $this->radiusCheck('2101009'));

        $this->assertSame(1, $report['deactivate_candidates']);
        $this->assertSame(1, $report['deactivated']);
        $this->assertSame(0, $report['deactivate_failed']);
        $this->assertNull($report['deactivate_skipped']);
        $this->assertSame(['2101010'], $report['deactivate_samples']);
    }

    /**
     * Pengaman paling penting di sini. Respons yang terpotong di tengah paging
     * terlihat persis seperti ratusan mahasiswa yang keluar sekaligus — dan tanpa
     * pengaman ini, satu tarikan gagal memutus WiFi seluruh kampus.
     */
    public function test_the_ratio_guard_cancels_every_deactivation_on_a_truncated_response(): void
    {
        $this->fakeStudents($this->nims(10));
        $this->sync()->run(self::ROUTER);

        // Lima dari sepuluh: jauh di bawah ambang 80%.
        $this->fakeStudents($this->nims(5));
        $report = $this->sync()->run(self::ROUTER);

        $this->assertSame(5, $report['deactivate_candidates'], 'Calonnya tetap dihitung supaya bisa dilaporkan.');
        $this->assertSame(0, $report['deactivated']);
        $this->assertStringContainsString('80%', (string) $report['deactivate_skipped']);
        $this->assertStringContainsString('--force', (string) $report['deactivate_skipped'],
            'Operator harus diberi tahu cara melanjutkan bila penurunannya memang benar.');
        $this->assertSame(0, HotspotVoucher::where('status', HotspotVoucher::STATUS_DISABLED)->count());

        // Dan --force memang melewatinya — pengaman ini menahan, bukan melarang.
        $this->fakeStudents($this->nims(5));
        $forced = $this->sync()->run(self::ROUTER, ['force' => true]);

        $this->assertSame(5, $forced['deactivated']);
        $this->assertNull($forced['deactivate_skipped']);
        $this->assertSame(5, HotspotVoucher::where('status', HotspotVoucher::STATUS_DISABLED)->count());
    }

    /**
     * Tarikan bersaring tidak pernah menonaktifkan, dan --force tidak membuatnya
     * sahih: pada tarikan satu prodi, "tidak ada di respons" cuma berarti bukan
     * prodi itu. Pengaman rasio saja tidak menangkap ini — 892 voucher dengan
     * tarikan satu prodi berisi 40 orang tetap terlihat seperti penurunan wajar
     * bila yang dibandingkan hanya jumlahnya.
     */
    public function test_a_filtered_pull_never_deactivates_not_even_with_force(): void
    {
        $this->fakeStudents($this->nims(10));
        $this->sync()->run(self::ROUTER);

        $this->fakeStudents(['2101001']);
        $report = $this->sync()->run(self::ROUTER, ['prodi' => 18, 'force' => true]);

        $this->assertSame(0, $report['deactivated']);
        $this->assertSame(0, $report['deactivate_candidates']);
        $this->assertStringContainsString('disaring (prodi)', (string) $report['deactivate_skipped']);

        // Batas jumlah memotong daftar dengan cara yang sama, jadi ia pun disaring.
        $this->fakeStudents($this->nims(10));
        $limited = $this->sync()->run(self::ROUTER, ['limit' => 1, 'force' => true]);

        $this->assertStringContainsString('batas jumlah', (string) $limited['deactivate_skipped']);
        $this->assertSame(0, HotspotVoucher::where('status', HotspotVoucher::STATUS_DISABLED)->count());
    }

    public function test_an_empty_response_is_never_read_as_every_student_leaving(): void
    {
        $this->fakeStudents($this->nims(10));
        $this->sync()->run(self::ROUTER);

        $this->fakeStudents([]);
        $report = $this->sync()->run(self::ROUTER, ['force' => true]);

        $this->assertSame(0, $report['total']);
        $this->assertSame(0, $report['deactivated']);
        $this->assertStringContainsString('kosong', (string) $report['deactivate_skipped']);
        $this->assertSame(10, HotspotVoucher::where('status', HotspotVoucher::STATUS_PENDING)->count());
    }

    /**
     * Dosen, staf, dan tamu memang tidak akan pernah muncul di daftar mahasiswa
     * PMB. Kalau mereka ikut dihitung, tarikan pertama yang berhasil justru
     * memutus akses seluruh pegawai.
     */
    public function test_manual_and_imported_vouchers_are_never_deactivated(): void
    {
        $dosen = $this->nonPmbVoucher('dosen.ari', HotspotVoucher::SOURCE_MANUAL);
        $tamu = $this->nonPmbVoucher('tamu-001', HotspotVoucher::SOURCE_IMPORT);

        $this->fakeStudents($this->nims(10));
        $report = $this->sync()->run(self::ROUTER);

        $this->assertSame(0, $report['deactivate_candidates']);
        $this->assertSame([], $report['deactivate_samples']);
        $this->assertSame(HotspotVoucher::STATUS_SYNCED, $dosen->refresh()->status);
        $this->assertSame(HotspotVoucher::STATUS_SYNCED, $tamu->refresh()->status);
    }

    public function test_deactivation_can_be_turned_off_for_one_run(): void
    {
        $this->fakeStudents($this->nims(10));
        $this->sync()->run(self::ROUTER);

        $this->fakeStudents($this->nims(9));
        $report = $this->sync()->run(self::ROUTER, ['deactivate' => false]);

        $this->assertSame(0, $report['deactivated']);
        $this->assertSame(0, $report['deactivate_candidates']);
        $this->assertNotNull($report['deactivate_skipped']);
        $this->assertSame(HotspotVoucher::STATUS_PENDING, HotspotVoucher::where('nim', '2101010')->value('status'));
    }

    /**
     * Sinkronisasi hanya MENUTUP akses otomatis, tidak pernah membukanya.
     * Mahasiswa yang selesai cuti kembali ke pending — RADIUS masih menolaknya
     * sampai ada yang menekan Terapkan — sementara blokir operator tidak ikut
     * dibatalkan.
     */
    public function test_a_returning_nim_goes_back_to_pending_while_an_operator_block_stands(): void
    {
        $this->fakeStudents($this->nims(10));
        $this->sync()->run(self::ROUTER);
        app(VoucherRadiusApplier::class)->apply(HotspotVoucher::all());

        $this->fakeStudents($this->nims(9));
        $this->sync()->run(self::ROUTER);

        // Blokir lewat tombol sengaja tanpa alasan tertulis; itulah yang
        // membedakannya dari baris yang dimatikan sinkronisasi.
        HotspotVoucher::where('nim', '2101009')->update([
            'status' => HotspotVoucher::STATUS_DISABLED,
            'disabled_reason' => null,
        ]);

        $this->fakeStudents($this->nims(10));
        $report = $this->sync()->run(self::ROUTER);

        $returning = HotspotVoucher::where('nim', '2101010')->sole();
        $this->assertSame(HotspotVoucher::STATUS_PENDING, $returning->status);
        $this->assertNull($returning->disabled_reason);
        $this->assertSame(1, $report['revived']);

        $this->assertSame('Reject', $this->radiusCheck('2101010')['Auth-Type'] ?? null,
            'RADIUS baru terbuka setelah voucher diterapkan, bukan karena satu respons API.');

        $this->assertSame(HotspotVoucher::STATUS_DISABLED, HotspotVoucher::where('nim', '2101009')->value('status'));
    }

    public function test_dry_run_reports_the_deactivation_candidates_without_touching_anything(): void
    {
        $this->fakeStudents($this->nims(10));
        $this->sync()->run(self::ROUTER);

        $this->fakeStudents($this->nims(9));
        $report = $this->sync()->run(self::ROUTER, ['dry_run' => true]);

        $this->assertSame(1, $report['deactivate_candidates']);
        $this->assertSame(['2101010'], $report['deactivate_samples']);
        $this->assertSame(0, $report['deactivated']);
        $this->assertSame(HotspotVoucher::STATUS_PENDING, HotspotVoucher::where('nim', '2101010')->value('status'));
        $this->assertSame([], $this->radiusCheck('2101010'), 'Dry run tidak boleh menyentuh RADIUS.');
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

    /**
     * Satu halaman berisi tepat NIM yang diminta.
     *
     * Jumlahnya harus bisa diatur karena pengaman rasio membandingkan banyaknya
     * NIM dari PMB dengan jumlah voucher `pmb` yang sudah ada: dengan fixture
     * empat orang milik test di atas, satu mahasiswa hilang saja sudah cukup untuk
     * menyalakan pengaman itu (ceil(4 × 0,8) = 4 > 3) dan tidak ada penonaktifan
     * yang bisa diuji sama sekali.
     *
     * @param  array<int,string>  $nims
     */
    private function fakeStudents(array $nims): void
    {
        $rows = array_map(fn (string $nim) => $this->student($nim, '1988-05-30'), $nims);

        Http::fake([self::API . '*' => Http::response([
            'success' => true,
            'message' => 'Data mahasiswa',
            'data' => $rows,
            'meta' => [
                'total' => count($rows),
                'count' => count($rows),
                'per_page' => 1000,
                'total_pages' => 1,
                'has_more_pages' => false,
            ],
            'list_program_studi' => [
                ['id' => 18, 'nama_program_studi' => 'S1 Informatika'],
            ],
        ])]);
    }

    /**
     * NIM berurutan mulai 2101001.
     *
     * @return array<int,string>
     */
    private function nims(int $count): array
    {
        return $count < 1 ? [] : array_map(fn (int $i) => (string) (2101000 + $i), range(1, $count));
    }

    /** Voucher yang bukan dari PMB dan sudah aktif — dosen, staf, atau tamu. */
    private function nonPmbVoucher(string $nim, string $source): HotspotVoucher
    {
        return HotspotVoucher::create([
            'nim' => $nim,
            'password' => 'rahasia' . $nim,
            'router_host' => self::ROUTER,
            'source' => $source,
            'status' => HotspotVoucher::STATUS_SYNCED,
        ]);
    }
}
