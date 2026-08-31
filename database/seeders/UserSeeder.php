<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class UserSeeder extends Seeder
{
    /**
     * Daftar kanonik izin aplikasi. Ditaruh di sini, bukan diulang di setiap
     * seeder, karena duplikasi daftarnya sudah pernah memakan korban: izin
     * 'view device credentials' ada di DatabaseSeeder tapi tidak ikut di daftar
     * seeder ini, jadi akun yang di-seed di server tidak pernah memegangnya dan
     * tombol buka-password di inventaris tidak pernah muncul di sana.
     *
     * {@see DatabaseSeeder} mengambil daftar yang sama dari konstanta ini.
     */
    public const PERMISSIONS = [
        'manage users',
        'manage master data',
        'manage devices',
        'manage maintenance',
        'view dashboard',
        'view reports',
        // Izin tersendiri, bukan bagian dari 'manage devices': mengelola
        // inventaris (nama, lokasi, SN) dan membaca password login router adalah
        // dua kewenangan berbeda. Teknisi bisa perlu yang pertama tanpa perlu
        // yang kedua.
        'view device credentials',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Ensure Super Admin role exists
        $superAdminRole = Role::firstOrCreate([
            'name' => 'Super Admin',
            'guard_name' => 'web',
        ]);

        foreach (self::PERMISSIONS as $permissionName) {
            Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
        }

        // Sengaja Permission::all(), bukan mengulang daftar di atas: Super Admin
        // harus memegang SEMUA izin yang ada di database, termasuk izin yang
        // dibuat seeder lain atau ditambahkan belakangan. Dengan begini, satu
        // izin baru tidak lagi bisa lolos dari akun ini hanya karena daftarnya
        // lupa diperbarui.
        $superAdminRole->givePermissionTo(Permission::all());

        // Create or update the single PUSTIK UBG user
        $user = User::updateOrCreate(
            ['email' => 'pustikubg26@gmail.com'],
            [
                'name' => 'PUSTIK UBG',
                'password' => Hash::make('walnutcreek2026#'),
                'email_verified_at' => now(),
            ]
        );

        // Assign Super Admin role
        $user->assignRole($superAdminRole);

        // Peta izin Spatie di-cache 24 jam (config/permission.php). Tanpa reset
        // di akhir, izin yang baru diberikan di sini belum tentu terbaca oleh
        // request berikutnya di server.
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
