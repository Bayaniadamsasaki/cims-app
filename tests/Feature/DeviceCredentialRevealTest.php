<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Fitur "mata" buka/tutup password perangkat di modal detail perangkat.
 *
 * Password sengaja TIDAK ikut di props halaman: ia hanya keluar lewat endpoint
 * `devices.credential`, satu perangkat per permintaan, dipagari izin sendiri,
 * dan tercatat di activity log. Tes ini menjaga kedua sisinya sekaligus —
 * pemegang izin benar-benar bisa membaca passwordnya, dan halaman inventaris
 * tetap tidak pernah membawa password ke browser seperti sebelum FIX-1.
 */
class DeviceCredentialRevealTest extends TestCase
{
    use RefreshDatabase;

    private const PLAIN = 'rahasia-router-2026';

    private const PERMISSION = 'view device credentials';

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate(self::PERMISSION, 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function deviceWithCredential(): Device
    {
        return Device::factory()->create([
            'username' => 'cims-app',
            'password_encrypted' => encrypt(self::PLAIN),
        ]);
    }

    /** Operator yang memang berhak membaca kredensial perangkat. */
    private function operator(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(self::PERMISSION);

        return $user;
    }

    public function test_an_authorized_operator_can_read_the_stored_password(): void
    {
        $device = $this->deviceWithCredential();

        $response = $this->actingAs($this->operator())
            ->getJson(route('devices.credential', $device->id))
            ->assertOk();

        $this->assertSame(self::PLAIN, $response->json('password'));
        $this->assertSame('cims-app', $response->json('username'));
        $this->assertTrue($response->json('has_credentials'));
    }

    public function test_a_user_without_the_permission_is_refused(): void
    {
        $device = $this->deviceWithCredential();

        $this->actingAs(User::factory()->create())
            ->getJson(route('devices.credential', $device->id))
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login_page_instead(): void
    {
        $device = $this->deviceWithCredential();

        $this->get(route('devices.credential', $device->id))
            ->assertRedirect(route('login'));
    }

    public function test_a_device_without_a_credential_reports_that_instead_of_a_value(): void
    {
        $device = Device::factory()->create(['password_encrypted' => null]);

        $response = $this->actingAs($this->operator())
            ->getJson(route('devices.credential', $device->id))
            ->assertOk();

        $this->assertNull($response->json('password'));
        $this->assertFalse($response->json('has_credentials'));
    }

    /**
     * Penjaga regresi untuk FIX-1: menambah tombol mata TIDAK boleh membuat
     * password ikut lagi di props halaman. Kalau assertion ini jebol, seluruh
     * password router kampus kembali tercetak di HTML setiap inventaris dibuka —
     * terbaca lewat devtools tanpa satu klik pun.
     */
    public function test_the_inventory_page_still_ships_no_password_even_for_an_authorized_operator(): void
    {
        $this->deviceWithCredential();

        $this->actingAs($this->operator())
            ->get(route('devices.index'))
            ->assertOk()
            ->assertDontSee(self::PLAIN, false)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('devices.0', fn (AssertableInertia $device) => $device
                    ->where('has_credentials', true)
                    ->missing('password')
                    ->missing('password_plain')
                    ->missing('password_encrypted')
                    ->etc()
                )
            );
    }

    public function test_every_reveal_is_logged_without_the_password_itself(): void
    {
        $device = $this->deviceWithCredential();
        $operator = $this->operator();

        $this->actingAs($operator)
            ->getJson(route('devices.credential', $device->id))
            ->assertOk();

        $activity = Activity::where('log_name', 'device-credential')->latest('id')->first();

        $this->assertNotNull($activity, 'Pembukaan kredensial harus meninggalkan jejak audit.');
        $this->assertSame($operator->id, $activity->causer_id);
        $this->assertSame($device->id, $activity->subject_id);
        $this->assertTrue($activity->properties['revealed']);
        $this->assertStringNotContainsString(self::PLAIN, json_encode($activity->properties));
    }

    /** Response berisi kredensial tidak boleh nyangkut di cache browser/proxy. */
    public function test_the_response_forbids_caching(): void
    {
        $device = $this->deviceWithCredential();

        $response = $this->actingAs($this->operator())
            ->getJson(route('devices.credential', $device->id))
            ->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /** Baris hasil import lama tersimpan tanpa enkripsi; nilainya tetap terbaca. */
    public function test_rows_stored_before_encryption_existed_are_still_readable(): void
    {
        $device = Device::factory()->create(['password_encrypted' => 'plaintext-lama']);

        $this->actingAs($this->operator())
            ->getJson(route('devices.credential', $device->id))
            ->assertOk()
            ->assertJsonPath('password', 'plaintext-lama');
    }

    public function test_a_device_removed_after_the_page_loaded_yields_404(): void
    {
        $this->actingAs($this->operator())
            ->getJson(route('devices.credential', 424242))
            ->assertNotFound();
    }

    /**
     * Satu akun yang bocor tidak boleh bisa menyapu seluruh kredensial router
     * kampus dalam satu putaran; itulah gunanya throttle di route-nya.
     */
    public function test_the_endpoint_is_rate_limited(): void
    {
        $device = $this->deviceWithCredential();
        $operator = $this->operator();

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($operator)
                ->getJson(route('devices.credential', $device->id))
                ->assertOk();
        }

        $this->actingAs($operator)
            ->getJson(route('devices.credential', $device->id))
            ->assertStatus(429);
    }
}
