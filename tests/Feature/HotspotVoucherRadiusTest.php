<?php

namespace Tests\Feature;

use App\Models\HotspotVoucher;
use App\Models\User;
use App\Services\MikrotikService;
use App\Services\RadiusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\InteractsWithRadius;
use Tests\TestCase;

/**
 * Voucher hotspot berakhir di database FreeRADIUS, bukan lagi di
 * /ip/hotspot/user milik tiap router.
 *
 * Yang dijaga test ini bukan cuma "barisnya masuk", tapi tiga hal yang paling
 * mahal kalau salah:
 *
 *   1. CIMS tidak pernah menghapus atribut milik konfigurasi lain. Satu
 *      database RADIUS bisa dipakai layanan lain, dan DELETE yang terlalu luas
 *      memutus layanan yang tidak ada hubungannya dengan voucher hotspot.
 *   2. Penerapan dua kali tidak menumpuk baris. radusergroup tidak punya kunci
 *      unik, jadi satu-satunya yang menahannya adalah pola hapus-lalu-tulis.
 *   3. RADIUS mati tidak boleh mengubah status apa pun di CIMS. Menandai
 *      ratusan voucher 'failed' karena satu koneksi putus hanya membuang jejak
 *      status yang sebelumnya benar.
 */
class HotspotVoucherRadiusTest extends TestCase
{
    use InteractsWithRadius;
    use RefreshDatabase;

    /** Group paket uji — nama group kampus yang asli hidup di .env. */
    private const GROUP = 'group-uji';

    /** Blok dokumentasi RFC 5737, bukan IP router kampus. */
    private const HOST = '198.51.100.1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRadiusDatabase();
        $this->seedRadiusGroup(self::GROUP);

        config([
            'services.hotspot.router_host' => self::HOST,
            'services.hotspot.default_profile' => null,
            'services.hotspot.radius.default_group' => self::GROUP,
        ]);

        // Penerapan voucher tidak lagi menyentuh API RouterOS sama sekali. Mock
        // yang diam ini menjaga test tetap berjalan tanpa router; test yang
        // memang menguntungkan dari itu memasang shouldNotReceive sendiri.
        $this->mock(MikrotikService::class, fn (MockInterface $mock) => $mock->shouldIgnoreMissing());
    }

    public function test_push_writes_credentials_and_group_to_radius_and_marks_the_row_synced(): void
    {
        $voucher = $this->voucher('2101001');

        // Penerapan ke RADIUS murni SQL: satu pun perintah RouterOS tidak boleh
        // ikut terkirim, karena router tidak lagi menyimpan daftar usernya.
        $this->mock(MikrotikService::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('upsertHotspotUser');
            $mock->shouldNotReceive('testConnection');
        });

        $this->actingAs(User::factory()->create())
            ->post(route('hotspot.vouchers.push'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $check = $this->radiusCheck('2101001');
        $this->assertSame('rahasia2101001', $check['Cleartext-Password'] ?? null);
        $this->assertArrayNotHasKey('Auth-Type', $check, 'Voucher aktif tidak boleh punya baris penolakan.');

        // ':=' berarti "ganti nilainya". Dengan '=' password lama justru menang
        // dan perubahan kredensial tidak pernah berlaku.
        $this->assertSame(':=', $this->radiusDb()->table('radcheck')
            ->where('username', '2101001')->where('attribute', 'Cleartext-Password')->value('op'));

        $this->assertSame([self::GROUP], $this->radiusGroupsOf('2101001'),
            'Profile kosong harus jatuh ke HOTSPOT_RADIUS_DEFAULT_GROUP.');

        $voucher->refresh();
        $this->assertSame(HotspotVoucher::STATUS_SYNCED, $voucher->status);
        $this->assertNotNull($voucher->synced_at);
        $this->assertNull($voucher->last_error);
    }

    public function test_session_timeout_is_sent_only_for_vouchers_that_limit_uptime(): void
    {
        $this->voucher('2101001', ['limit_uptime' => '2h']);
        $this->voucher('2101002');

        $this->actingAs(User::factory()->create())
            ->post(route('hotspot.vouchers.push'))
            ->assertSessionHas('success');

        $this->assertSame(['Session-Timeout' => '7200'], $this->radiusReply('2101001'));
        $this->assertSame([], $this->radiusReply('2101002'),
            'Voucher tanpa batas uptime tidak boleh menitipkan atribut reply apa pun.');
    }

    /**
     * Kolom yang tidak punya padanan di RADIUS tidak boleh diam-diam menjadi
     * atribut. `server` adalah nama hotspot server RouterOS, dan `valid_until`
     * belum dipaksakan sama sekali — halaman voucher menyebut keduanya catatan,
     * dan test ini yang menjaga label itu tetap benar.
     */
    public function test_hotspot_server_and_valid_until_are_never_projected_to_radius(): void
    {
        $this->voucher('2101001', ['server' => 'hotspot1', 'valid_until' => '2026-12-31']);

        $this->actingAs(User::factory()->create())
            ->post(route('hotspot.vouchers.push'))
            ->assertSessionHas('success');

        $this->assertSame(['Cleartext-Password'], array_keys($this->radiusCheck('2101001')));
        $this->assertSame([], $this->radiusReply('2101001'));
    }

    public function test_push_is_aborted_when_radius_cannot_be_reached(): void
    {
        $voucher = $this->voucher('2101001');

        $this->breakRadiusConnection();

        $this->actingAs(User::factory()->create())
            ->post(route('hotspot.vouchers.push'))
            ->assertRedirect()
            ->assertSessionHas('error')
            ->assertSessionMissing('success');

        // Status tidak boleh ikut jatuh ke 'failed': tidak ada satu baris pun yang
        // dicoba, dan menghapus jejak 'pending' hanya menyesatkan operator.
        $voucher->refresh();
        $this->assertSame(HotspotVoucher::STATUS_PENDING, $voucher->status);
        $this->assertNull($voucher->last_error);
    }

    public function test_applying_the_same_voucher_twice_does_not_duplicate_radius_rows(): void
    {
        $voucher = $this->voucher('2101001');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('hotspot.vouchers.push-one', $voucher->id))
            ->assertSessionHas('success');

        // Kredensialnya berubah lalu diterapkan lagi — inilah jalur yang paling
        // sering dilewati voucher, dan yang paling mudah menumpuk baris.
        $voucher->forceFill(['password' => 'passwordbaru'])->save();

        $this->actingAs($user)->post(route('hotspot.vouchers.push-one', $voucher->id))
            ->assertSessionHas('success');

        $this->assertSame(1, $this->radiusDb()->table('radcheck')->where('username', '2101001')->count());
        $this->assertSame(1, $this->radiusDb()->table('radusergroup')->where('username', '2101001')->count());
        $this->assertSame('passwordbaru', $this->radiusCheck('2101001')['Cleartext-Password'] ?? null);
    }

    public function test_blocking_a_voucher_rejects_it_in_radius_without_erasing_its_password(): void
    {
        $voucher = $this->voucher('2101001');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('hotspot.vouchers.push'))->assertSessionHas('success');

        $this->actingAs($user)->post(route('hotspot.vouchers.toggle', $voucher->id))
            ->assertSessionHas('success');

        $check = $this->radiusCheck('2101001');
        $this->assertSame('Reject', $check['Auth-Type'] ?? null);
        $this->assertSame('rahasia2101001', $check['Cleartext-Password'] ?? null,
            'Blokir tidak boleh menulis ulang kredensial; membukanya cuma menghapus satu baris.');
        $this->assertSame(HotspotVoucher::STATUS_DISABLED, $voucher->refresh()->status);

        // Blokir lewat tombol sengaja tanpa alasan tertulis: sinkronisasi PMB hanya
        // menghidupkan kembali baris yang disabled_reason-nya terisi, jadi keputusan
        // operator tidak ikut dibatalkan tarikan berikutnya.
        $this->assertNull($voucher->disabled_reason);

        $this->actingAs($user)->post(route('hotspot.vouchers.toggle', $voucher->id))
            ->assertSessionHas('success');

        $this->assertArrayNotHasKey('Auth-Type', $this->radiusCheck('2101001'));
        $this->assertSame(HotspotVoucher::STATUS_SYNCED, $voucher->refresh()->status,
            'Voucher yang kredensialnya sudah pernah sampai ke RADIUS kembali ke synced, bukan pending.');
    }

    public function test_deleting_a_voucher_revokes_its_credentials_from_radius(): void
    {
        $voucher = $this->voucher('2101001', ['limit_uptime' => '1h']);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('hotspot.vouchers.push'))->assertSessionHas('success');

        $this->actingAs($user)->delete(route('hotspot.vouchers.destroy', $voucher->id))
            ->assertSessionHas('success');

        // Ketiga tabel harus bersih. Kalau radcheck tertinggal, mahasiswa yang
        // vouchernya sudah hilang dari halaman ini tetap bisa login.
        $this->assertSame([], $this->radiusCheck('2101001'));
        $this->assertSame([], $this->radiusReply('2101001'));
        $this->assertSame([], $this->radiusGroupsOf('2101001'));
        $this->assertSame(0, HotspotVoucher::count());
    }

    /**
     * Pengaman termahal di service ini: database RADIUS bukan milik CIMS.
     *
     * Atribut di luar MANAGED_CHECK/MANAGED_REPLY bisa milik VPN, PPPoE, atau
     * konfigurasi lain yang sudah berjalan bertahun-tahun. Semua jalur tulis CIMS
     * — terapkan, blokir, terapkan ulang, hapus — dilewati sekaligus di sini.
     */
    public function test_radius_rows_owned_by_other_services_are_never_deleted(): void
    {
        $voucher = $this->voucher('2101001');
        $user = User::factory()->create();

        $this->seedForeignRadiusRows('2101001');

        $this->actingAs($user)->post(route('hotspot.vouchers.push'))->assertSessionHas('success');
        $this->actingAs($user)->post(route('hotspot.vouchers.toggle', $voucher->id))->assertSessionHas('success');
        $this->actingAs($user)->post(route('hotspot.vouchers.push-one', $voucher->id))->assertSessionHas('success');
        $this->actingAs($user)->delete(route('hotspot.vouchers.destroy', $voucher->id))->assertSessionHas('success');

        $check = $this->radiusCheck('2101001');
        $this->assertSame('31 Dec 2030', $check['Expiration'] ?? null);
        $this->assertSame('3', $check['Simultaneous-Use'] ?? null);
        $this->assertSame('10.10.10.10', $this->radiusReply('2101001')['Framed-IP-Address'] ?? null);

        // Sekaligus bukti yang sebaliknya: milik CIMS memang ikut terhapus.
        $this->assertArrayNotHasKey('Cleartext-Password', $check);
        $this->assertArrayNotHasKey('Auth-Type', $check);
    }

    /**
     * "Terapkan semua" tidak boleh membuka blokir siapa pun.
     *
     * Yang ditulis ke RADIUS untuk voucher terblokir justru penolakannya sendiri,
     * jadi statusnya sengaja tidak dinaikkan ke synced. Kalau naik, penerapan
     * berikutnya akan mencabut blokir itu tanpa ada yang memintanya.
     */
    public function test_reapplying_a_batch_keeps_a_blocked_voucher_blocked(): void
    {
        $blocked = $this->voucher('2101001', [
            'status' => HotspotVoucher::STATUS_DISABLED,
            'disabled_reason' => 'tidak ada di PMB',
        ]);
        $active = $this->voucher('2101002');

        $this->actingAs(User::factory()->create())
            ->post(route('hotspot.vouchers.push'), ['ids' => [$blocked->id, $active->id]])
            ->assertSessionHas('success');

        $this->assertSame('Reject', $this->radiusCheck('2101001')['Auth-Type'] ?? null);
        $this->assertSame(HotspotVoucher::STATUS_DISABLED, $blocked->refresh()->status);
        $this->assertSame('tidak ada di PMB', $blocked->disabled_reason);

        $this->assertArrayNotHasKey('Auth-Type', $this->radiusCheck('2101002'));
        $this->assertSame(HotspotVoucher::STATUS_SYNCED, $active->refresh()->status);
    }

    /**
     * Halaman voucher tidak boleh mati hanya karena server RADIUS mati: kedua
     * nilai ini dipakai sebagai prop halaman, bukan sebagai hasil aksi.
     */
    public function test_health_and_group_listing_report_failure_instead_of_throwing(): void
    {
        $this->breakRadiusConnection();

        $radius = app(RadiusService::class);
        $health = $radius->health();

        $this->assertFalse($health['success']);
        $this->assertNotNull($health['error']);
        $this->assertSame(0, $health['users']);
        $this->assertSame([], $radius->groups());
    }

    /**
     * Satu NIM kini berlaku di seluruh kampus, jadi daftarnya tidak lagi disaring
     * per router — dan sebab nonaktif ikut dihitung, karena "disabled" tanpa
     * alasan membuat operator menebak apakah itu keputusannya sendiri atau hasil
     * sinkronisasi PMB.
     */
    public function test_page_lists_every_router_and_counts_disabled_vouchers_by_reason(): void
    {
        $this->voucher('2101001');
        $this->voucher('2101002', ['router_host' => '198.51.100.9']);
        $this->voucher('2101003', [
            'status' => HotspotVoucher::STATUS_DISABLED,
            'disabled_reason' => 'tidak ada di PMB',
        ]);
        $this->voucher('2101004', [
            'status' => HotspotVoucher::STATUS_DISABLED,
            'disabled_reason' => 'tidak ada di PMB',
        ]);
        $this->voucher('2101005', ['status' => HotspotVoucher::STATUS_DISABLED]);

        $this->actingAs(User::factory()->create())
            ->get(route('hotspot.vouchers.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Hotspot/Vouchers')
                ->has('vouchers.data', 5)
                ->where('stats.total', 5)
                ->where('stats.pending', 2)
                ->where('stats.disabled', 3)
                ->where('disabledReasons.tidak ada di PMB', 2)
                ->where('disabledReasons.diblokir operator', 1)
                ->where('hotspot.radius_configured', true)
                ->where('hotspot.default_profile', self::GROUP)
                ->etc());
    }

    /**
     * Voucher pending siap diterapkan. Password sengaja bukan NIM-nya sendiri
     * supaya test membuktikan yang sampai ke radcheck memang kolom password.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function voucher(string $nim, array $attributes = []): HotspotVoucher
    {
        return HotspotVoucher::create($attributes + [
            'nim' => $nim,
            'password' => 'rahasia'.$nim,
            'router_host' => self::HOST,
            'status' => HotspotVoucher::STATUS_PENDING,
        ]);
    }
}
