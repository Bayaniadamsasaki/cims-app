<?php

namespace Tests\Feature;

use App\Models\HotspotVoucher;
use App\Support\VoucherDeduplicator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Satu mahasiswa harus tinggal satu baris sebelum kunci unik pindah ke NIM.
 *
 * Selama voucher dikunci per (NIM, router), satu mahasiswa punya satu baris untuk
 * tiap alamat router yang pernah dipakai — dan alamat router hotspot kampus
 * memang bergeser sendiri karena ia DHCP client. RADIUS tidak mengenal itu:
 * username cuma ada satu.
 *
 * Migrasi ke kunci NIM tunggal memanggil deduplikator yang sama, dan
 * penghapusannya TIDAK bisa dibatalkan migrate:rollback. Karena itu aturan
 * "siapa yang menang" diuji di sini, bukan dipercayai saat migrasi berjalan.
 */
class VoucherDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Migrasi sudah memasang unique('nim'), jadi keadaan sebelum migrasi
        // harus dipulihkan dulu — tanpa ini baris kembar tidak bisa dibuat sama
        // sekali dan yang teruji cuma database yang sudah bersih.
        Schema::table('hotspot_vouchers', function (Blueprint $table) {
            $table->dropUnique('hotspot_vouchers_nim_unique');
        });
    }

    public function test_the_newest_row_wins_when_one_nim_exists_on_two_routers(): void
    {
        // Baris basi: router lama, password lama.
        $this->row('2101001', '198.51.100.9', 'passwordlama', '2026-08-28 09:00:00');

        // Baris terbaru: hasil sinkronisasi PMB paling akhir.
        $this->row('2101001', '198.51.100.1', 'passwordbaru', '2026-09-02 09:00:00');

        // NIM tanpa kembaran tidak boleh ikut tersentuh.
        $this->row('2101002', '198.51.100.1', 'passwordlain', '2026-09-02 09:00:00');

        $this->artisan('hotspot:vouchers-dedupe')->assertSuccessful();

        $this->assertSame(2, HotspotVoucher::count());

        $keeper = HotspotVoucher::where('nim', '2101001')->sole();
        $this->assertSame('passwordbaru', $keeper->password);
        $this->assertSame('198.51.100.1', $keeper->router_host);

        $this->assertSame('passwordlain', HotspotVoucher::where('nim', '2101002')->sole()->password);

        // Buktinya datanya benar-benar sah untuk langkah migrasi berikutnya:
        // kunci unik pada NIM sekarang bisa dipasang tanpa error.
        Schema::table('hotspot_vouchers', function (Blueprint $table) {
            $table->unique('nim');
        });
    }

    public function test_dry_run_counts_the_duplicates_without_deleting_anything(): void
    {
        $this->row('2101001', '198.51.100.9', 'passwordlama', '2026-08-28 09:00:00');
        $this->row('2101001', '198.51.100.1', 'passwordbaru', '2026-09-02 09:00:00');
        $this->row('2101002', '198.51.100.9', 'sama', '2026-08-28 09:00:00');
        $this->row('2101002', '198.51.100.1', 'sama', '2026-09-02 09:00:00');

        $report = app(VoucherDeduplicator::class)->run(apply: false);

        $this->assertSame(4, $report['total']);
        $this->assertSame(2, $report['unique']);
        $this->assertSame(2, $report['duplicate_nims']);
        $this->assertSame(2, $report['deleted']);

        // Hanya satu NIM yang passwordnya benar-benar berbeda antar router — itulah
        // yang perlu diberitahukan, karena mahasiswanya akan berganti password.
        $this->assertSame(1, $report['password_conflicts']);
        $this->assertFalse($report['applied']);
        $this->assertSame(4, HotspotVoucher::count(), 'Dry run tidak boleh menghapus satu baris pun.');
    }

    /**
     * Dua baris yang updated_at-nya identik memang terjadi: satu tarikan PMB
     * menulis semuanya dalam detik yang sama. Tanpa pemecah seri, mana yang
     * bertahan menjadi urusan urutan baca database — dan password mahasiswa tidak
     * boleh ditentukan hal seperti itu.
     */
    public function test_a_tie_on_updated_at_is_broken_by_the_newest_id(): void
    {
        $this->row('2101001', '198.51.100.9', 'passworddulu', '2026-09-02 09:00:00');
        $this->row('2101001', '198.51.100.1', 'passwordkemudian', '2026-09-02 09:00:00');

        app(VoucherDeduplicator::class)->run(apply: true);

        $this->assertSame('passwordkemudian', HotspotVoucher::where('nim', '2101001')->sole()->password);
    }

    public function test_a_clean_table_is_reported_as_needing_no_change(): void
    {
        $this->row('2101001', '198.51.100.1', 'satu', '2026-09-02 09:00:00');
        $this->row('2101002', '198.51.100.1', 'dua', '2026-09-02 09:00:00');

        $report = app(VoucherDeduplicator::class)->run(apply: true);

        $this->assertSame(0, $report['deleted']);
        $this->assertSame(0, $report['duplicate_nims']);
        $this->assertSame([], $report['samples']);
        $this->assertSame(2, HotspotVoucher::count());
    }

    /**
     * Baris ditulis lewat query builder, bukan model: updated_at harus dipatok
     * tepat karena itulah yang menentukan pemenangnya, dan model akan
     * menimpanya dengan waktu sekarang.
     */
    private function row(string $nim, string $host, string $password, string $updatedAt): void
    {
        DB::table('hotspot_vouchers')->insert([
            'nim' => $nim,
            'password' => $password,
            'router_host' => $host,
            'status' => HotspotVoucher::STATUS_PENDING,
            'source' => HotspotVoucher::SOURCE_MANUAL,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }
}
