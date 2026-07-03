<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Building;
use App\Models\DeviceCategory;
use App\Models\Device;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CimsBackendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create basic permissions
        Permission::create(['name' => 'manage users']);
        Permission::create(['name' => 'manage master data']);
        Permission::create(['name' => 'manage devices']);

        // Create roles
        $superAdmin = Role::create(['name' => 'Super Admin']);
        $superAdmin->givePermissionTo(Permission::all());

        $netAdmin = Role::create(['name' => 'Network Administrator']);
        $netAdmin->givePermissionTo(['manage master data', 'manage devices']);

        $tech = Role::create(['name' => 'Technician']);
    }

    public function test_a_user_can_login_with_correct_credentials()
    {
        $user = User::factory()->create([
            'email' => 'admin@cims.com',
            'password' => bcrypt('password'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'admin@cims.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'user' => [
                    'id', 'name', 'email', 'roles', 'permissions'
                ]
            ]);
    }

    public function test_a_user_cannot_login_with_incorrect_password()
    {
        $user = User::factory()->create([
            'email' => 'admin@cims.com',
            'password' => bcrypt('password'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'admin@cims.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_authenticated_user_can_get_profile()
    {
        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/me');

        $response->assertStatus(200)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_super_admin_can_crud_users()
    {
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');

        // 1. Create User
        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/users', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'roles' => ['Technician'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'John Doe');

        $userId = $response->json('data.id');

        // 2. Read User
        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/users/{$userId}");
        $response->assertStatus(200)
            ->assertJsonPath('data.email', 'john@example.com');

        // 3. Update User
        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/users/{$userId}", [
            'name' => 'John Updated',
        ]);
        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'John Updated');

        // 4. Delete User
        $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/users/{$userId}");
        $response->assertStatus(200);

        $this->assertDatabaseMissing('users', ['id' => $userId]);
    }

    public function test_non_admin_cannot_manage_users()
    {
        $techUser = User::factory()->create();
        $techUser->assignRole('Technician');

        $response = $this->actingAs($techUser, 'sanctum')->getJson('/api/users');
        $response->assertStatus(403);
    }

    public function test_network_admin_can_crud_buildings()
    {
        $netAdmin = User::factory()->create();
        $netAdmin->assignRole('Network Administrator');

        // 1. Create Building
        $response = $this->actingAs($netAdmin, 'sanctum')->postJson('/api/buildings', [
            'name' => 'Gedung A',
            'code' => 'GDA',
            'description' => 'Gedung utama perkuliahan.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Gedung A');

        $buildingId = $response->json('data.id');

        // 2. Read Building
        $response = $this->actingAs($netAdmin, 'sanctum')->getJson("/api/buildings/{$buildingId}");
        $response->assertStatus(200)
            ->assertJsonPath('data.code', 'GDA');

        // 3. Update Building
        $response = $this->actingAs($netAdmin, 'sanctum')->putJson("/api/buildings/{$buildingId}", [
            'name' => 'Gedung A Baru',
        ]);
        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Gedung A Baru');

        // 4. Delete Building
        $response = $this->actingAs($netAdmin, 'sanctum')->deleteJson("/api/buildings/{$buildingId}");
        $response->assertStatus(200);
    }

    public function test_network_admin_can_crud_devices()
    {
        $netAdmin = User::factory()->create();
        $netAdmin->assignRole('Network Administrator');

        $category = DeviceCategory::create(['name' => 'Switch']);
        $vendor = Vendor::create(['name' => 'Cisco']);
        $building = Building::create(['name' => 'Gedung Rektorat', 'code' => 'REK']);

        // 1. Create Device
        $response = $this->actingAs($netAdmin, 'sanctum')->postJson('/api/devices', [
            'name' => 'Switch 1',
            'hostname' => 'sw-rek-01',
            'ip_address' => '192.168.1.10',
            'mac_address' => '00:11:22:33:44:55',
            'device_category_id' => $category->id,
            'vendor_id' => $vendor->id,
            'building_id' => $building->id,
            'status' => 'active',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Switch 1');

        $deviceId = $response->json('data.id');

        // 2. Read Device
        $response = $this->actingAs($netAdmin, 'sanctum')->getJson("/api/devices/{$deviceId}");
        $response->assertStatus(200)
            ->assertJsonPath('data.hostname', 'sw-rek-01');

        // 3. Update Device
        $response = $this->actingAs($netAdmin, 'sanctum')->putJson("/api/devices/{$deviceId}", [
            'name' => 'Switch 1 Updated',
        ]);
        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Switch 1 Updated');

        // 4. Delete Device
        $response = $this->actingAs($netAdmin, 'sanctum')->deleteJson("/api/devices/{$deviceId}");
        $response->assertStatus(200);
    }
}
