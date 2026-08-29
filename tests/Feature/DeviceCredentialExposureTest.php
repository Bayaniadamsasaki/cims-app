<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Support\DeviceCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Password perangkat jaringan dulu ikut ter-serialize lewat accessor
 * `password_plain` yang di-`$appends`, jadi setiap props halaman inventaris dan
 * setiap response API mengirim password router ke browser dalam bentuk
 * plaintext. Tes ini menjaga jalur-jalur itu tetap tertutup, tanpa mematikan
 * kemampuan backend mengambil kredensialnya saat memang perlu login ke
 * perangkat.
 */
class DeviceCredentialExposureTest extends TestCase
{
    use RefreshDatabase;

    private const PLAIN = 'rahasia-router-2026';

    private function deviceWithCredential(): Device
    {
        return Device::factory()->create([
            'username' => 'cims-app',
            'password_encrypted' => encrypt(self::PLAIN),
        ]);
    }

    public function test_model_serialization_carries_neither_the_plaintext_nor_the_ciphertext(): void
    {
        $array = $this->deviceWithCredential()->fresh()->toArray();

        $this->assertArrayNotHasKey('password_plain', $array);
        $this->assertArrayNotHasKey('password_encrypted', $array);
        $this->assertStringNotContainsString(self::PLAIN, json_encode($array));
        $this->assertTrue($array['has_credentials']);
    }

    public function test_a_device_without_a_stored_credential_is_reported_as_such(): void
    {
        $device = Device::factory()->create(['password_encrypted' => null]);

        $this->assertFalse($device->fresh()->has_credentials);
    }

    public function test_the_inventory_page_does_not_ship_the_password_to_the_browser(): void
    {
        $this->deviceWithCredential();

        $this->actingAs(User::factory()->create())
            ->get(route('devices.index'))
            ->assertOk()
            ->assertDontSee(self::PLAIN, false)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('devices.0', fn (AssertableInertia $device) => $device
                    ->where('has_credentials', true)
                    ->missing('password_plain')
                    ->missing('password_encrypted')
                    ->etc()
                )
            );
    }

    public function test_the_api_resource_exposes_only_whether_a_credential_exists(): void
    {
        $device = $this->deviceWithCredential();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson("/api/devices/{$device->id}")->assertOk();

        $this->assertTrue($response->json('data.has_credentials'));
        $this->assertArrayNotHasKey('password', $response->json('data'));
        $this->assertArrayNotHasKey('password_plain', $response->json('data'));
        $this->assertStringNotContainsString(self::PLAIN, $response->getContent());
    }

    public function test_the_backend_can_still_read_the_credential_for_a_device_connection(): void
    {
        $device = $this->deviceWithCredential();

        $this->assertSame(self::PLAIN, DeviceCredential::password($device->fresh()));
        $this->assertTrue(DeviceCredential::exists($device->fresh()));
    }

    public function test_a_device_without_a_credential_yields_no_password(): void
    {
        $device = Device::factory()->create(['password_encrypted' => null]);

        $this->assertNull(DeviceCredential::password($device->fresh()));
        $this->assertFalse(DeviceCredential::exists($device->fresh()));
    }

    /**
     * Baris hasil import lama tersimpan tanpa enkripsi. Nilainya harus tetap
     * bisa dipakai, kalau tidak monitoring perangkat tertua justru mati.
     */
    public function test_rows_stored_before_encryption_existed_are_still_usable(): void
    {
        $device = Device::factory()->create(['password_encrypted' => 'plaintext-lama']);

        $this->assertSame('plaintext-lama', DeviceCredential::password($device->fresh()));
    }

    public function test_the_credential_column_is_kept_out_of_the_activity_log(): void
    {
        $device = $this->deviceWithCredential();
        $device->update(['password_encrypted' => encrypt('password-baru')]);

        $logged = json_encode(Activity::all()->pluck('properties'));

        $this->assertNotSame('[]', $logged, 'Activity log harus terisi, kalau tidak tes ini tidak menguji apa pun.');
        $this->assertStringNotContainsString('password_encrypted', $logged);
        $this->assertStringNotContainsString(self::PLAIN, $logged);
        $this->assertStringNotContainsString('password-baru', $logged);
    }

    /**
     * Field password di form tidak lagi bisa di-prefill, jadi form yang disimpan
     * tanpa mengisinya tidak boleh menghapus kredensial yang sudah tersimpan.
     */
    public function test_saving_the_form_without_a_new_password_keeps_the_stored_one(): void
    {
        $device = $this->deviceWithCredential();

        $this->actingAs(User::factory()->create())
            ->post(route('devices.update', $device->id), [
                'name' => 'Router Utama',
                'password' => '',
            ])
            ->assertRedirect(route('devices.index'));

        $this->assertSame(self::PLAIN, DeviceCredential::password($device->fresh()));
    }
}
