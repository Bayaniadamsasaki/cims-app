<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Akun PUSTIK UBG yang dibuat {@see UserSeeder} harus benar-benar memegang
 * seluruh izin aplikasi.
 *
 * Tes ini ada karena kasus nyata: izin 'view device credentials' ditambahkan ke
 * DatabaseSeeder tetapi daftar di UserSeeder lupa ikut diperbarui, jadi akun
 * yang di-seed di server tidak pernah memegangnya — tombol buka-password di
 * inventaris tidak pernah muncul di sana sementara di local baik-baik saja.
 * Selama tes ini hijau, izin baru tidak bisa lolos dari akun ini tanpa
 * ketahuan.
 */
class SuperAdminPermissionSeedingTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'pustikubg26@gmail.com';

    private function seededSuperAdmin(): User
    {
        $this->seed(UserSeeder::class);

        return User::where('email', self::EMAIL)->firstOrFail();
    }

    public function test_the_seeded_account_holds_every_defined_permission(): void
    {
        $held = $this->seededSuperAdmin()->getAllPermissions()->pluck('name');

        foreach (UserSeeder::PERMISSIONS as $permission) {
            $this->assertTrue(
                $held->contains($permission),
                "Akun PUSTIK UBG tidak memegang izin '{$permission}'."
            );
        }
    }

    public function test_the_seeded_account_can_read_device_credentials(): void
    {
        // Disebut eksplisit, terpisah dari tes daftar di atas: inilah izin yang
        // menentukan tombol mata di modal detail perangkat digambar atau tidak.
        $this->assertTrue(
            $this->seededSuperAdmin()->can('view device credentials')
        );
    }

    public function test_a_permission_created_outside_this_seeder_still_reaches_the_account(): void
    {
        // Skenario izin baru yang dibuat seeder atau migrasi lain lebih dulu.
        // Super Admin disinkronkan dari Permission::all(), jadi izin semacam ini
        // harus ikut terbawa tanpa perlu menyentuh UserSeeder::PERMISSIONS.
        Permission::findOrCreate('manage something new', 'web');

        $this->assertTrue(
            $this->seededSuperAdmin()->can('manage something new')
        );
    }

    public function test_running_the_seeder_twice_changes_nothing(): void
    {
        $this->seed(UserSeeder::class);
        $this->seed(UserSeeder::class);

        // Seeder ini dijalankan ulang setiap deploy, jadi harus idempoten: satu
        // akun, dan satu baris per izin.
        $this->assertSame(1, User::where('email', self::EMAIL)->count());
        $this->assertSame(
            count(UserSeeder::PERMISSIONS),
            Permission::where('guard_name', 'web')->count()
        );

        $user = User::where('email', self::EMAIL)->firstOrFail();
        $this->assertSame(['Super Admin'], $user->getRoleNames()->all());
    }
}
