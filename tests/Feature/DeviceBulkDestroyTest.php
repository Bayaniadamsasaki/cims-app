<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Hapus massal perangkat dari checkbox pilihan di tabel inventaris.
 *
 * Endpoint ini menghapus banyak baris sekaligus dan tidak bisa dibatalkan, jadi
 * yang dijaga tes ini ada tiga: hanya pemegang 'manage devices' yang boleh
 * memanggilnya, jumlah yang terhapus persis sebanyak yang dipilih (bukan lebih),
 * dan pola route-nya tidak bertukar dengan `devices.destroy` yang berbagi awalan
 * URL yang sama.
 */
class DeviceBulkDestroyTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSION = 'manage devices';

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate(self::PERMISSION, 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Operator yang memang berhak mengelola inventaris perangkat. */
    private function operator(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(self::PERMISSION);

        return $user;
    }

    public function test_an_authorized_operator_deletes_exactly_the_selected_devices(): void
    {
        $selected = Device::factory()->count(3)->create();
        $spared = Device::factory()->create();

        $response = $this->actingAs($this->operator())
            ->delete(route('devices.bulk-destroy'), [
                'ids' => $selected->pluck('id')->all(),
            ]);

        $response->assertRedirect(route('devices.index'));
        $response->assertSessionHas('success');

        foreach ($selected as $device) {
            $this->assertDatabaseMissing('devices', ['id' => $device->id]);
        }

        // Baris yang tidak dicentang harus utuh — sekali endpoint ini menghapus
        // lebih banyak dari yang dipilih, tidak ada cara mengembalikannya.
        $this->assertDatabaseHas('devices', ['id' => $spared->id]);
    }

    public function test_child_rows_of_a_deleted_device_go_with_it(): void
    {
        $device = Device::factory()->create();
        $interface = DeviceInterface::create([
            'device_id' => $device->id,
            'interface_name' => 'ether1',
            'interface_type' => 'ethernet',
            'interface_status' => 'up',
        ]);

        $this->actingAs($this->operator())
            ->delete(route('devices.bulk-destroy'), ['ids' => [$device->id]]);

        // Cascade-nya ada di level database, bukan di kode — kalau suatu saat
        // migrasi kolomnya diganti tanpa cascade, tes ini yang jatuh lebih dulu
        // ketimbang inventaris produksi meninggalkan interface yatim.
        $this->assertDatabaseMissing('device_interfaces', ['id' => $interface->id]);
    }

    public function test_a_user_without_the_permission_cannot_bulk_delete(): void
    {
        $device = Device::factory()->create();

        $response = $this->actingAs(User::factory()->create())
            ->delete(route('devices.bulk-destroy'), ['ids' => [$device->id]]);

        $response->assertForbidden();
        $this->assertDatabaseHas('devices', ['id' => $device->id]);
    }

    public function test_a_guest_cannot_bulk_delete(): void
    {
        $device = Device::factory()->create();

        $this->delete(route('devices.bulk-destroy'), ['ids' => [$device->id]])
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('devices', ['id' => $device->id]);
    }

    public function test_an_empty_selection_is_rejected_by_validation(): void
    {
        $device = Device::factory()->create();

        $this->actingAs($this->operator())
            ->delete(route('devices.bulk-destroy'), ['ids' => []])
            ->assertSessionHasErrors('ids');

        $this->assertDatabaseHas('devices', ['id' => $device->id]);
    }

    public function test_a_selection_larger_than_one_page_is_rejected(): void
    {
        // Halaman inventaris memuat maksimal 100 baris, jadi permintaan yang
        // membawa lebih dari itu tidak mungkin berasal dari pilihan checkbox.
        $this->actingAs($this->operator())
            ->delete(route('devices.bulk-destroy'), ['ids' => range(1, 101)])
            ->assertSessionHasErrors('ids');
    }

    public function test_non_numeric_ids_are_rejected(): void
    {
        $this->actingAs($this->operator())
            ->delete(route('devices.bulk-destroy'), ['ids' => ['bulk-destroy']])
            ->assertSessionHasErrors('ids.0');
    }

    /**
     * `/devices/bulk-destroy` dan `/devices/{id}` berbagi awalan URL yang sama.
     * Kalau urutan deklarasinya tertukar suatu saat, request hapus massal akan
     * mendarat di `destroy()` sebagai id "bulk-destroy" dan gagal tanpa sebab
     * yang jelas — jadi pemetaannya dipatok di sini.
     */
    public function test_the_bulk_route_is_not_swallowed_by_the_single_delete_route(): void
    {
        $route = app('router')->getRoutes()->match(
            \Illuminate\Http\Request::create(route('devices.bulk-destroy'), 'DELETE')
        );

        $this->assertSame('bulkDestroy', $route->getActionMethod());
    }
}
