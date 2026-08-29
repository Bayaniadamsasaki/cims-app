<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Services\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Invalid user name or password" dari RouterOS tidak menyebut user mana yang
 * dipakai. Kredensial dicari dari inventaris berdasarkan IP yang sama persis,
 * jadi begitu alamat router bergeser, koneksi diam-diam jatuh ke user global
 * .env dan gagal login — pesannya harus menyebutkan itu.
 */
class MikrotikCredentialSourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Loopback dengan port tertutup: koneksi ditolak seketika, tidak ada trafik
     * keluar, dan yang diuji tetap resolusi kredensial sebelum socket dibuka.
     */
    private const CLOSED = '127.0.0.1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mikrotik.host' => self::CLOSED,
            'services.mikrotik.port' => 9,
            'services.mikrotik.user' => 'user-global-env',
            'services.mikrotik.password' => 'rahasia',
            'services.mikrotik.attempts' => 1,
            'services.mikrotik.timeout' => 1,
        ]);
    }

    public function test_it_names_the_env_fallback_when_no_inventory_row_matches_the_host(): void
    {
        $result = app(MikrotikService::class)->testConnection(self::CLOSED);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('.env MIKROTIK_USER', $result['credential_source']);
        $this->assertStringContainsString(self::CLOSED, $result['credential_source']);
    }

    public function test_it_names_the_inventory_row_that_supplied_the_credentials(): void
    {
        $device = Device::factory()->create([
            'ip_address' => self::CLOSED,
            'username' => 'cims-app',
            'password_encrypted' => encrypt('rahasia'),
        ]);

        $result = app(MikrotikService::class)->testConnection(self::CLOSED);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString("inventaris #{$device->id}", $result['credential_source']);
        $this->assertStringNotContainsString('fallback', $result['credential_source']);
    }

    public function test_the_credential_source_is_appended_only_to_login_failures(): void
    {
        $service = app(MikrotikService::class);

        // Host tidak terjangkau: pesan aslinya sudah menyebut host & port, jadi
        // tidak perlu diberi keterangan kredensial.
        $result = $service->testConnection(self::CLOSED);
        $this->assertStringNotContainsString('MIKROTIK_USER', $result['error']);

        $this->assertTrue(
            $this->invokeAuthCheck($service, 'Invalid user name or password'),
            'Kegagalan login harus dikenali sebagai masalah kredensial.'
        );
        $this->assertFalse(
            $this->invokeAuthCheck($service, 'Unable to establish socket session'),
            'Host tidak terjangkau bukan masalah kredensial.'
        );
    }

    private function invokeAuthCheck(MikrotikService $service, string $message): bool
    {
        $method = new \ReflectionMethod($service, 'looksLikeAuthFailure');

        return $method->invoke($service, $message);
    }
}
