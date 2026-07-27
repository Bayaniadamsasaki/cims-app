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

        // Ensure permissions exist and assign to Super Admin role
        $permissions = [
            'manage users',
            'manage master data',
            'manage devices',
            'manage maintenance',
            'view dashboard',
            'view reports',
        ];

        foreach ($permissions as $permissionName) {
            $perm = Permission::firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
            $superAdminRole->givePermissionTo($perm);
        }

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
    }
}
