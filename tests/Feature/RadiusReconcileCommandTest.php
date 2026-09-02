<?php

namespace Tests\Feature;

use App\Models\HotspotVoucher;
use App\Support\VoucherRadiusApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRadius;
use Tests\TestCase;

/**
 * `radius:reconcile` adalah satu-satunya tempat selisih CIMS↔RADIUS terlihat.
 *
 * Kolom status voucher cuma mencatat apa yang terjadi saat tombol Terapkan
 * ditekan; ia tidak tahu kalau baris RADIUS-nya kemudian hilang, diedit dari
 * mysql client, atau gagal ditulis karena koneksi mati di tengah jalan. Karena
 * itu yang diuji di sini bukan hitungannya, melainkan dua selisih yang tidak
 * kelihatan di tampilan mana pun — blokir yang tidak sampai ke RADIUS dan
 * penolakan yang tertinggal di sana — plus dua janji yang membuat perintah ini
 * aman dijalankan di database RADIUS bersama: tanpa `--fix` tidak ada satu pun
 * penulisan, dan username yang bukan milik CIMS tidak pernah dihapus.
 */
class RadiusReconcileCommandTest extends TestCase
{
    use InteractsWithRadius;
    use RefreshDatabase;

    /** Router pencatat; setelah pindah ke RADIUS nilainya cuma catatan historis. */
    private const ROUTER = '198.51.100.5';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRadiusDatabase();

        config(['services.hotspot.radius.default_group' => 'mahasiswa']);
    }

    public function test_a_projection_that_matches_is_reported_as_having_no_drift(): void
    {
        $this->applied('2101001');

        $this->artisan('radius:reconcile')
            ->expectsOutputToContain('Tidak ada selisih')
            ->assertExitCode(0);
    }

    /**
     * Selisih yang paling berbahaya: CIMS menyebut voucher diblokir, RADIUS masih
     * menerima loginnya. Mahasiswa yang dikira sudah diputus tetap online dan
     * tidak ada halaman yang menunjukkannya.
     */
    public function test_a_block_that_never_reached_radius_is_reported_and_repaired(): void
    {
        $voucher = $this->applied('2101001');

        // Blokir yang hanya sampai ke CIMS — seperti penulisan yang gagal saat
        // operator menutup tab, atau koneksi RADIUS yang mati tepat setelah itu.
        $voucher->update([
            'status' => HotspotVoucher::STATUS_DISABLED,
            'disabled_reason' => 'diblokir operator',
        ]);

        $this->artisan('radius:reconcile')
            ->expectsOutputToContain('RADIUS masih menerima login')
            ->expectsOutputToContain('--fix')
            ->assertExitCode(0);

        $this->assertArrayNotHasKey('Auth-Type', $this->radiusCheck('2101001'),
            'Tanpa --fix perintah ini harus read-only di kedua database.');

        $this->artisan('radius:reconcile', ['--fix' => true])->assertExitCode(0);

        $this->assertSame('Reject', $this->radiusCheck('2101001')['Auth-Type'] ?? null);
        $this->assertSame(HotspotVoucher::STATUS_DISABLED, $voucher->refresh()->status,
            'Memperbaiki RADIUS tidak boleh membuka blokirnya di CIMS.');
    }

    /**
     * Kebalikannya, dan sama tidak kelihatannya: RADIUS menolak NIM yang menurut
     * CIMS aktif. Mahasiswanya mengeluh tidak bisa login sementara halaman
     * voucher menyebutnya "Aktif di RADIUS".
     */
    public function test_a_reject_left_behind_in_radius_is_found_and_cleared(): void
    {
        $this->applied('2101001');

        $this->radiusDb()->table('radcheck')->insert([
            'username' => '2101001', 'attribute' => 'Auth-Type', 'op' => ':=', 'value' => 'Reject',
        ]);

        $this->artisan('radius:reconcile', ['--fix' => true])
            ->expectsOutputToContain('RADIUS menolak, padahal tidak diblokir di CIMS')
            ->assertExitCode(0);

        $this->assertArrayNotHasKey('Auth-Type', $this->radiusCheck('2101001'));
    }

    public function test_a_voucher_missing_from_radius_is_written_and_stamped_synced(): void
    {
        $voucher = HotspotVoucher::create([
            'nim' => '2101001',
            'password' => 'rahasia',
            'router_host' => self::ROUTER,
            'source' => HotspotVoucher::SOURCE_PMB,
            'status' => HotspotVoucher::STATUS_PENDING,
        ]);

        $this->artisan('radius:reconcile')
            ->expectsOutputToContain('Belum ada di RADIUS')
            ->assertExitCode(0);

        $this->assertSame([], $this->radiusCheck('2101001'));

        $this->artisan('radius:reconcile', ['--fix' => true])->assertExitCode(0);

        $this->assertSame('rahasia', $this->radiusCheck('2101001')['Cleartext-Password'] ?? null);

        // Profile voucher kosong, jadi group-nya diambil dari HOTSPOT_RADIUS_DEFAULT_GROUP.
        $this->assertSame(['mahasiswa'], $this->radiusGroupsOf('2101001'));
        $this->assertSame(HotspotVoucher::STATUS_SYNCED, $voucher->refresh()->status);
    }

    /** hotspot_vouchers yang menang, bukan isi RADIUS: RADIUS cuma proyeksinya. */
    public function test_a_password_edited_directly_in_radius_is_pulled_back(): void
    {
        $this->applied('2101001');

        $this->radiusDb()->table('radcheck')
            ->where('username', '2101001')
            ->where('attribute', 'Cleartext-Password')
            ->update(['value' => 'diubah-dari-mysql']);

        $this->artisan('radius:reconcile', ['--fix' => true])
            ->expectsOutputToContain('Password di RADIUS berbeda')
            ->assertExitCode(0);

        $this->assertSame('30051988', $this->radiusCheck('2101001')['Cleartext-Password'] ?? null);
    }

    /**
     * Username asing tidak bisa dibedakan antara sisa voucher yang gagal dicabut
     * dan milik layanan lain di database RADIUS yang sama (VPN, PPPoE). Karena
     * tidak bisa dibedakan, --fix melaporkannya saja dan tidak pernah menghapus.
     */
    public function test_a_username_cims_does_not_own_is_reported_but_never_deleted(): void
    {
        $this->applied('2101001');

        $this->radiusDb()->table('radcheck')->insert([
            'username' => 'pppoe-kantor', 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => 'bukan-milik-cims',
        ]);

        $this->artisan('radius:reconcile', ['--fix' => true])
            ->expectsOutputToContain('pppoe-kantor')
            ->assertExitCode(0);

        $this->assertSame('bukan-milik-cims', $this->radiusCheck('pppoe-kantor')['Cleartext-Password'] ?? null);
    }

    /** RADIUS mati bukan berarti "tidak ada selisih" — itu harus jadi kegagalan. */
    public function test_an_unreachable_radius_is_a_failure_not_an_empty_report(): void
    {
        $this->breakRadiusConnection();

        $this->artisan('radius:reconcile', ['--fix' => true])
            ->expectsOutputToContain('radius:doctor')
            ->assertExitCode(1);
    }

    /** Voucher yang sudah diterapkan ke RADIUS, seperti setelah tombol Terapkan ditekan. */
    private function applied(string $nim): HotspotVoucher
    {
        $voucher = HotspotVoucher::create([
            'nim' => $nim,
            'password' => '30051988',
            'profile' => 'mahasiswa',
            'router_host' => self::ROUTER,
            'source' => HotspotVoucher::SOURCE_PMB,
            'status' => HotspotVoucher::STATUS_PENDING,
        ]);

        app(VoucherRadiusApplier::class)->apply([$voucher]);

        return $voucher->refresh();
    }
}
