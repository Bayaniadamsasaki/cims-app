<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InteractsWithRadius;
use Tests\TestCase;

/**
 * Halaman Paket Hotspot: isi dari group RADIUS yang dipakai voucher mahasiswa.
 *
 * Halaman voucher menentukan siapa memakai paket mana (radusergroup); halaman
 * ini menentukan apa isi paketnya (radgroupreply). Yang dijaga test ini adalah
 * empat hal yang salahnya tidak pernah muncul sebagai error:
 *
 *   1. Urutan rx/tx pada Mikrotik-Rate-Limit. Tertukar berarti unduhan
 *      mahasiswa dibatasi angka unggah, dan tidak ada yang melaporkannya.
 *   2. Kolom batas yang dikosongkan harus MENGHAPUS barisnya, bukan menulis 0 —
 *      `Session-Timeout := 0` memutus sesi seketika.
 *   3. Menyimpan formulir hanya menyentuh atribut yang dikelola CIMS. Baris yang
 *      disetel dengan tangan di server RADIUS harus selamat.
 *   4. Menghapus paket yang masih dipakai justru MELEBARKAN akses (login tanpa
 *      batas), jadi penolakannya harus ada dan harus terbukti.
 */
class HotspotPackageTest extends TestCase
{
    use InteractsWithRadius;
    use RefreshDatabase;

    /** Izin yang sudah ada dipakai ulang; permission baru tidak dipegang siapa pun sampai seeder jalan lagi. */
    private const PERMISSION = 'manage devices';

    private const GROUP = 'paket-uji';

    /** Blok dokumentasi RFC 5737, bukan router kampus. */
    private const HOST = '198.51.100.1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpRadiusDatabase();

        Permission::findOrCreate(self::PERMISSION, 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        config(['services.hotspot.router_host' => self::HOST]);

        // app.blade.php me-resolve chunk halaman dari manifest Vite, jadi tanpa ini
        // test prop di bawah gagal hanya karena `npm run build` belum dijalankan —
        // kegagalan yang tidak ada hubungannya dengan yang sedang diuji.
        $this->withoutVite();

        // Halaman ini tidak butuh router untuk menyimpan; daftar user-profile-nya
        // cuma pembanding dan dikirim sebagai deferred prop.
        $this->mock(MikrotikService::class, fn (MockInterface $mock) => $mock->shouldIgnoreMissing());
    }

    public function test_creating_a_package_writes_the_rate_limit_with_upload_first(): void
    {
        $this->actingAs($this->operator())
            ->post(route('hotspot.packages.store'), $this->payload())
            ->assertSessionHas('success');

        // Paket "unduh 8, unggah 2" — rx dulu, dari sudut pandang router.
        $this->assertSame(['Mikrotik-Rate-Limit' => '2M/8M'], $this->policyOf(self::GROUP));

        // op ':=' berarti "ganti nilainya". Dengan '=' atribut lama menang dan
        // paket yang baru disimpan tidak pernah benar-benar berlaku.
        $this->assertSame(':=', $this->radiusDb()->table('radgroupreply')
            ->where('groupname', self::GROUP)->value('op'));
    }

    /**
     * Nilai yang dilaporkan operator: 2 Mbps untuk kedua arah, dikirim sebagai
     * string persis seperti yang dilakukan input number di browser.
     *
     * Dulu nilai ini tidak pernah sampai ke sini. Input-nya memakai step="0.1" di
     * atas min="0.064", dan HTML menghitung langkah dari min — jadi browser menolak
     * setiap angka bulat sebelum formulirnya terkirim ("nilai terdekat 1.964 dan
     * 2.064"). Yang bisa dijaga PHP hanya ujung satunya: 2 diterima, dan jadi 2M/2M.
     */
    public function test_a_whole_number_of_megabits_is_accepted_and_becomes_two_megabits_each_way(): void
    {
        $this->actingAs($this->operator())
            ->post(route('hotspot.packages.store'), $this->payload([
                'download' => '2',
                'upload' => '2',
            ]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame('2M/2M', $this->policyOf(self::GROUP)['Mikrotik-Rate-Limit']);
    }

    /**
     * Batas yang diiklankan atribut min pada input halaman, dijaga juga di server:
     * 0.064 diterima, di bawahnya tidak. Sekaligus membuktikan formulir yang ditolak
     * tidak menyentuh policy yang sudah tersimpan.
     */
    public function test_the_smallest_speed_the_form_offers_is_accepted_and_below_it_is_not(): void
    {
        $operator = $this->operator();

        $this->actingAs($operator)
            ->post(route('hotspot.packages.store'), $this->payload([
                'download' => '0.064',
                'upload' => '0.064',
            ]))
            ->assertSessionHas('success');

        $this->assertSame('64k/64k', $this->policyOf(self::GROUP)['Mikrotik-Rate-Limit']);

        $this->actingAs($operator)
            ->post(route('hotspot.packages.update', self::GROUP), $this->payload([
                'download' => '0.05',
                'upload' => '1',
            ]))
            ->assertSessionHasErrors('download');

        $this->assertSame('64k/64k', $this->policyOf(self::GROUP)['Mikrotik-Rate-Limit']);
    }

    public function test_minutes_in_the_form_are_stored_as_seconds(): void
    {
        $this->actingAs($this->operator())
            ->post(route('hotspot.packages.store'), $this->payload([
                'session_timeout' => 45,
                'idle_timeout' => 10,
                'interim_interval' => 5,
            ]))
            ->assertSessionHas('success');

        $policy = $this->policyOf(self::GROUP);

        // Operator berpikir dalam menit; RADIUS tidak menerima satuan.
        $this->assertSame('2700', $policy['Session-Timeout']);
        $this->assertSame('600', $policy['Idle-Timeout']);
        $this->assertSame('300', $policy['Acct-Interim-Interval']);
    }

    /**
     * Baris yang bukan milik CIMS harus selamat dari penyimpanan formulir.
     *
     * Database RADIUS bisa dipakai layanan lain, dan atribut seperti Framed-Pool
     * biasanya disetel dengan tangan di server. Menyimpan formulir kecepatan
     * bukan alasan yang cukup untuk menghapusnya.
     */
    public function test_saving_replaces_only_the_attributes_this_page_manages(): void
    {
        $this->radiusDb()->table('radgroupreply')->insert([
            ['groupname' => self::GROUP, 'attribute' => 'Mikrotik-Rate-Limit', 'op' => ':=', 'value' => '1M/1M'],
            ['groupname' => self::GROUP, 'attribute' => 'Framed-Pool', 'op' => ':=', 'value' => 'hotspot-pool'],
        ]);

        $this->actingAs($this->operator())
            ->post(route('hotspot.packages.update', self::GROUP), $this->payload([
                'download' => 20,
                'upload' => 5,
            ]))
            ->assertSessionHas('success');

        $policy = $this->policyOf(self::GROUP);

        $this->assertSame('5M/20M', $policy['Mikrotik-Rate-Limit']);
        $this->assertSame('hotspot-pool', $policy['Framed-Pool']);

        // Hapus-lalu-tulis, bukan tambah: nilai lama tidak boleh menumpuk di
        // bawah nilai baru — yang teratas yang dipakai FreeRADIUS.
        $this->assertSame(1, $this->rowsFor(self::GROUP, 'Mikrotik-Rate-Limit'));
    }

    /**
     * Kolom batas yang dikosongkan berarti "tanpa batas itu", dan satu-satunya
     * cara menyatakannya di RADIUS adalah barisnya tidak ada. Menyimpannya
     * sebagai 0 memutus sesi mahasiswa seketika begitu ia login.
     */
    public function test_an_emptied_limit_field_deletes_the_row_instead_of_storing_zero(): void
    {
        $operator = $this->operator();

        $this->actingAs($operator)
            ->post(route('hotspot.packages.store'), $this->payload(['session_timeout' => 60]))
            ->assertSessionHas('success');

        $this->assertSame('3600', $this->policyOf(self::GROUP)['Session-Timeout']);

        $this->actingAs($operator)
            ->post(route('hotspot.packages.update', self::GROUP), $this->payload(['session_timeout' => null]))
            ->assertSessionHas('success');

        $this->assertArrayNotHasKey('Session-Timeout', $this->policyOf(self::GROUP));
        $this->assertSame(0, $this->rowsFor(self::GROUP, 'Session-Timeout'));
    }

    /**
     * Mode lanjut ditulis apa adanya. Nilai burst/threshold/priority tidak bisa
     * diwakili dua angka Mbps, jadi kalau diisi ia yang menang — bukan digabung
     * dengan angka di formulir sederhana yang tetap ikut terkirim.
     */
    public function test_an_advanced_rate_limit_value_is_stored_exactly_as_typed(): void
    {
        $raw = '2M/8M 4M/16M 3M/12M 8 8';

        $this->actingAs($this->operator())
            ->post(route('hotspot.packages.store'), $this->payload([
                'download' => 999,
                'upload' => 999,
                'rate_limit_raw' => $raw,
            ]))
            ->assertSessionHas('success');

        $this->assertSame($raw, $this->policyOf(self::GROUP)['Mikrotik-Rate-Limit']);
    }

    public function test_a_rate_limit_that_is_not_a_rate_limit_is_rejected(): void
    {
        $this->actingAs($this->operator())
            ->post(route('hotspot.packages.store'), $this->payload(['rate_limit_raw' => 'secepat mungkin']))
            ->assertSessionHasErrors('rate_limit_raw');

        $this->assertSame([], $this->policyOf(self::GROUP));
    }

    /**
     * Spasi ditolak: nama group ikut muncul di config router dan di file
     * konfigurasi FreeRADIUS, dan di sanalah spasi menjadi masalah yang sulit
     * dilihat — group-nya "ada", cuma tidak pernah cocok dengan yang mana pun.
     */
    public function test_a_group_name_with_a_space_is_rejected(): void
    {
        $this->actingAs($this->operator())
            ->post(route('hotspot.packages.store'), $this->payload(['name' => 'paket mahasiswa']))
            ->assertSessionHasErrors('name');

        $this->assertSame(0, $this->radiusDb()->table('radgroupreply')->count());
    }

    /**
     * "Buat baru" dengan nama yang sudah punya isi akan diam-diam menimpa paket
     * orang lain. Ditolak, dengan pesan yang menyuruh membukanya sebagai ubah.
     */
    public function test_creating_a_package_whose_name_already_has_policy_is_rejected(): void
    {
        $this->seedRadiusGroup(self::GROUP, '1M/1M');

        $this->actingAs($this->operator())
            ->post(route('hotspot.packages.store'), $this->payload())
            ->assertSessionHasErrors('name');

        $this->assertSame('1M/1M', $this->policyOf(self::GROUP)['Mikrotik-Rate-Limit']);
    }

    /**
     * Keadaan yang ditemukan di server sebelum halaman ini ada: group 'mahasiswa'
     * dipakai ratusan voucher tapi radgroupreply-nya kosong. Ia harus bisa diberi
     * isi lewat "buat paket", bukan ditolak sebagai nama yang sudah terpakai.
     */
    public function test_a_group_that_only_has_members_can_be_given_its_first_policy(): void
    {
        $this->joinGroup('2101001', self::GROUP);

        $this->actingAs($this->operator())
            ->post(route('hotspot.packages.store'), $this->payload())
            ->assertSessionHas('success');

        $this->assertSame('2M/8M', $this->policyOf(self::GROUP)['Mikrotik-Rate-Limit']);

        // Keanggotaannya milik halaman voucher dan tidak boleh tersentuh.
        $this->assertSame([self::GROUP], $this->radiusGroupsOf('2101001'));
    }

    /**
     * Menghapus policy tidak memutus siapa pun — anggotanya tetap login, hanya
     * tanpa batas kecepatan sama sekali. Kegagalan yang tidak terasa seperti
     * kegagalan itulah yang ditahan penjagaan ini.
     */
    public function test_deleting_is_refused_while_vouchers_still_use_the_package(): void
    {
        $this->seedRadiusGroup(self::GROUP, '2M/8M');
        $this->joinGroup('2101001', self::GROUP);

        $this->actingAs($this->operator())
            ->delete(route('hotspot.packages.destroy', self::GROUP))
            ->assertSessionHas('error');

        $this->assertSame('2M/8M', $this->policyOf(self::GROUP)['Mikrotik-Rate-Limit']);
    }

    /**
     * Penghapusan sengaja lebih luas daripada penyimpanan: menyimpan formulir
     * bisa terjadi tanpa sengaja, menghapus paket tidak, dan separuh policy yang
     * tertinggal justru membuat group-nya tetap terlihat "punya isi".
     *
     * radgroupcheck tidak ikut terhapus — grant CIMS di tabel itu hanya SELECT —
     * jadi sisanya harus dilaporkan, bukan didiamkan.
     */
    public function test_deleting_an_unused_package_clears_radgroupreply_and_reports_what_it_cannot_touch(): void
    {
        $this->radiusDb()->table('radgroupreply')->insert([
            ['groupname' => self::GROUP, 'attribute' => 'Mikrotik-Rate-Limit', 'op' => ':=', 'value' => '2M/8M'],
            ['groupname' => self::GROUP, 'attribute' => 'Framed-Pool', 'op' => ':=', 'value' => 'hotspot-pool'],
            ['groupname' => 'paket-lain', 'attribute' => 'Mikrotik-Rate-Limit', 'op' => ':=', 'value' => '1M/1M'],
        ]);

        $this->radiusDb()->table('radgroupcheck')->insert([
            'groupname' => self::GROUP, 'attribute' => 'Auth-Type', 'op' => ':=', 'value' => 'Accept',
        ]);

        $this->actingAs($this->operator())
            ->delete(route('hotspot.packages.destroy', self::GROUP))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'radgroupcheck'));

        $this->assertSame([], $this->policyOf(self::GROUP));
        $this->assertSame(1, $this->radiusDb()->table('radgroupcheck')->where('groupname', self::GROUP)->count());
        $this->assertSame('1M/1M', $this->policyOf('paket-lain')['Mikrotik-Rate-Limit']);
    }

    /**
     * Group tanpa policy harus TERLIHAT, bukan hilang dari daftar. Inilah satu
     * keadaan yang tidak pernah dilaporkan siapa pun: FreeRADIUS menjawab
     * Access-Accept tanpa atribut, mahasiswa login tanpa batas, dan tidak ada
     * error di mana pun.
     */
    public function test_a_group_with_members_but_no_policy_is_listed_and_flagged(): void
    {
        $this->joinGroup('2101001', self::GROUP);
        $this->joinGroup('2101002', self::GROUP);

        // radusergroup tidak punya kunci unik, jadi baris kembar itu wajar —
        // jumlah anggota harus dihitung distinct, bukan sebanyak barisnya.
        $this->joinGroup('2101002', self::GROUP);

        $this->actingAs($this->operator())
            ->get(route('hotspot.packages.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Hotspot/Packages')
                ->has('packages', 1)
                ->where('packages.0.name', self::GROUP)
                ->where('packages.0.has_policy', false)
                ->where('packages.0.members', 2)
                ->where('packages.0.rate_limit', null)
                ->where('canManage', true)
                ->etc());
    }

    public function test_a_package_with_policy_is_listed_with_its_speed_split_into_two_numbers(): void
    {
        $this->seedRadiusGroup(self::GROUP, '2M/8M');

        $this->actingAs($this->operator())
            ->get(route('hotspot.packages.index'))
            ->assertInertia(fn ($page) => $page
                ->where('packages.0.has_policy', true)
                ->where('packages.0.rate_limit', '2M/8M')
                // Bilangan bulat, bukan 2.0: json_encode membuang pecahan nol, dan
                // inilah yang benar-benar diterima halaman. Yang mengunci tipe float
                // di sisi PHP adalah MikrotikRateLimitTest.
                ->where('packages.0.speed.upload', 2)
                ->where('packages.0.speed.download', 8)
                ->where('packages.0.members', 0)
                ->etc());
    }

    /**
     * Halaman ini justru yang menjelaskan MENGAPA sebuah group tidak punya batas
     * kecepatan, jadi membacanya cukup dengan login — yang dijaga izin hanya
     * menulisnya, karena satu simpanan berlaku ke seluruh anggota paket sekaligus.
     */
    public function test_the_page_is_readable_without_the_permission_but_marked_read_only(): void
    {
        $this->seedRadiusGroup(self::GROUP);

        $this->actingAs(User::factory()->create())
            ->get(route('hotspot.packages.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Hotspot/Packages')
                ->where('canManage', false)
                ->where('radiusConfigured', true)
                ->has('managedAttributes')
                ->etc());
    }

    public function test_a_user_without_the_permission_cannot_change_packages(): void
    {
        $this->seedRadiusGroup(self::GROUP, '1M/1M');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('hotspot.packages.store'), $this->payload(['name' => 'paket-baru']))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('hotspot.packages.update', self::GROUP), $this->payload())
            ->assertForbidden();

        $this->actingAs($user)
            ->delete(route('hotspot.packages.destroy', self::GROUP))
            ->assertForbidden();

        $this->assertSame('1M/1M', $this->policyOf(self::GROUP)['Mikrotik-Rate-Limit']);
        $this->assertSame([], $this->policyOf('paket-baru'));
    }

    public function test_a_guest_cannot_read_or_change_packages(): void
    {
        $this->get(route('hotspot.packages.index'))->assertRedirect(route('login'));

        $this->post(route('hotspot.packages.store'), $this->payload())->assertRedirect(route('login'));

        $this->assertSame(0, $this->radiusDb()->table('radgroupreply')->count());
    }

    /** Operator yang memang berhak mengubah konfigurasi jaringan. */
    private function operator(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(self::PERMISSION);

        return $user;
    }

    /**
     * Isian formulir paket. Nilai kosong dikirim sebagai null, seperti input yang
     * dibiarkan kosong oleh operator.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => self::GROUP,
            'download' => 8,
            'upload' => 2,
            'session_timeout' => null,
            'idle_timeout' => null,
            'interim_interval' => null,
            'mikrotik_group' => null,
            'rate_limit_raw' => null,
        ];
    }

    /**
     * Policy satu paket sebagai attribute => value.
     *
     * @return array<string,string>
     */
    private function policyOf(string $group): array
    {
        return $this->radiusDb()->table('radgroupreply')
            ->where('groupname', $group)
            ->pluck('value', 'attribute')
            ->map(fn ($value) => (string) $value)
            ->all();
    }

    /** Jumlah baris satu atribut — yang membuktikan tidak ada nilai menumpuk. */
    private function rowsFor(string $group, string $attribute): int
    {
        return (int) $this->radiusDb()->table('radgroupreply')
            ->where('groupname', $group)
            ->where('attribute', $attribute)
            ->count();
    }

    /** Keanggotaan paket, seperti yang ditulis halaman voucher. */
    private function joinGroup(string $username, string $group): void
    {
        $this->radiusDb()->table('radusergroup')->insert([
            'username' => $username,
            'groupname' => $group,
            'priority' => 1,
        ]);
    }
}
