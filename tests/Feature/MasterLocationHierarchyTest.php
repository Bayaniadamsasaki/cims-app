<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Device;
use App\Models\Floor;
use App\Models\Rack;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Hierarki Master Location: Building → Floor → Room → Device. Setiap tingkat
 * harus berada di dalam tingkat di atasnya, dan lantai tidak boleh dobel di
 * gedung yang sama.
 */
class MasterLocationHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private function building(string $code, string $name = null): Building
    {
        return Building::create([
            'name' => $name ?? 'Gedung ' . $code,
            'code' => $code,
            'floors_count' => 0,
            'rooms_count' => 0,
        ]);
    }

    private function floor(Building $building, int $level): Floor
    {
        return Floor::create([
            'building_id' => $building->id,
            'name' => 'Lantai ' . $level,
            'level' => $level,
        ]);
    }

    public function test_floor_level_must_be_unique_within_the_same_building()
    {
        $user = User::factory()->create();
        $building = $this->building('GKB');
        $this->floor($building, 1);

        $this->actingAs($user)
            ->post(route('floors.store'), [
                'building_id' => $building->id,
                'name' => 'Lantai 1 Duplikat',
                'level' => 1,
            ])
            ->assertSessionHasErrors('level');

        $this->assertSame(1, Floor::where('building_id', $building->id)->count());
    }

    public function test_same_level_is_allowed_in_a_different_building()
    {
        $user = User::factory()->create();
        $first = $this->building('GKB');
        $second = $this->building('LAB');
        $this->floor($first, 1);

        $this->actingAs($user)
            ->post(route('floors.store'), [
                'building_id' => $second->id,
                'name' => 'Lantai 1',
                'level' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Floor::where('building_id', $second->id)->count());
    }

    public function test_floor_can_keep_its_level_while_being_renamed()
    {
        $user = User::factory()->create();
        $building = $this->building('GKB');
        $floor = $this->floor($building, 2);

        $this->actingAs($user)
            ->post(route('floors.update', $floor->id), [
                'building_id' => $building->id,
                'name' => 'Lantai Dua',
                'level' => 2,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Lantai Dua', $floor->fresh()->name);
    }

    public function test_floor_detail_page_only_exposes_its_own_rooms()
    {
        $user = User::factory()->create();
        $building = $this->building('GKB');
        $firstFloor = $this->floor($building, 1);
        $secondFloor = $this->floor($building, 2);

        Room::create(['floor_id' => $firstFloor->id, 'name' => 'Ruang Server', 'code' => 'GKB-F1-RS']);
        Room::create(['floor_id' => $firstFloor->id, 'name' => 'Lab Jaringan', 'code' => 'GKB-F1-R01']);
        Room::create(['floor_id' => $secondFloor->id, 'name' => 'Ruang Dosen', 'code' => 'GKB-F2-R01']);

        $this->actingAs($user)
            ->get(route('floors.show', $firstFloor->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Master/FloorDetail')
                ->where('floor.id', $firstFloor->id)
                ->where('floor.building.code', 'GKB')
                ->has('floor.rooms', 2)
            );
    }

    public function test_room_added_from_the_floor_detail_page_is_bound_to_that_floor()
    {
        $user = User::factory()->create();
        $building = $this->building('GKB');
        $floor = $this->floor($building, 3);

        // Halaman detail lantai mengirim floor_id saja — tanpa dropdown lantai.
        $this->actingAs($user)
            ->post(route('rooms.store'), [
                'floor_id' => $floor->id,
                'name' => 'Ruang Panel',
                'code' => 'GKB-F3-R01',
            ])
            ->assertSessionHasNoErrors();

        $room = Room::where('code', 'GKB-F3-R01')->firstOrFail();
        $this->assertSame($floor->id, $room->floor_id);
    }

    public function test_room_cannot_be_attached_to_a_floor_outside_the_selected_building()
    {
        $user = User::factory()->create();
        $target = $this->building('GKB');
        $other = $this->building('LAB');
        $foreignFloor = $this->floor($other, 1);

        $this->actingAs($user)
            ->post(route('rooms.store'), [
                'building_id' => $target->id,
                'floor_id' => $foreignFloor->id,
                'name' => 'Ruang Salah Gedung',
                'code' => 'GKB-F1-R99',
            ])
            ->assertSessionHasErrors('floor_id');

        $this->assertSame(0, Room::count());
    }

    public function test_rooms_page_provides_buildings_with_their_floors_for_the_cascading_select()
    {
        $user = User::factory()->create();
        $building = $this->building('GKB');
        $this->floor($building, 1);
        $this->floor($building, 2);

        $this->actingAs($user)
            ->get(route('rooms.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Master/Rooms')
                ->has('buildings', 1)
                ->has('buildings.0.floors', 2)
                ->where('buildings.0.floors.0.level', 1)
            );
    }

    public function test_rooms_page_can_be_filtered_down_to_a_single_floor()
    {
        $user = User::factory()->create();
        $building = $this->building('GKB');
        $firstFloor = $this->floor($building, 1);
        $secondFloor = $this->floor($building, 2);

        Room::create(['floor_id' => $firstFloor->id, 'name' => 'Ruang A', 'code' => 'GKB-F1-R01']);
        Room::create(['floor_id' => $secondFloor->id, 'name' => 'Ruang B', 'code' => 'GKB-F2-R01']);

        $this->actingAs($user)
            ->get(route('rooms.index', ['floor_id' => $secondFloor->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Master/Rooms')
                ->has('rooms', 1)
                ->where('rooms.0.code', 'GKB-F2-R01')
            );
    }

    public function test_floors_page_reports_used_levels_per_building()
    {
        $user = User::factory()->create();
        $building = $this->building('GKB');
        $this->floor($building, 1);
        $this->floor($building, 3);

        $this->actingAs($user)
            ->get(route('floors.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Master/Floors')
                ->where('usedLevels.' . $building->id, [1, 3])
            );
    }

    public function test_device_cannot_be_placed_on_a_floor_from_another_building()
    {
        $user = User::factory()->create();
        $target = $this->building('GKB');
        $other = $this->building('LAB');
        $foreignFloor = $this->floor($other, 1);

        $this->actingAs($user)
            ->post(route('devices.store'), [
                'name' => 'Switch Salah Lantai',
                'building_id' => $target->id,
                'floor_id' => $foreignFloor->id,
            ])
            ->assertSessionHasErrors('floor_id');

        $this->assertSame(0, Device::count());
    }

    public function test_device_cannot_be_placed_in_a_room_from_another_floor()
    {
        $user = User::factory()->create();
        $building = $this->building('GKB');
        $firstFloor = $this->floor($building, 1);
        $secondFloor = $this->floor($building, 2);
        $roomOnSecondFloor = Room::create([
            'floor_id' => $secondFloor->id,
            'name' => 'Ruang Lantai 2',
            'code' => 'GKB-F2-R01',
        ]);

        $this->actingAs($user)
            ->post(route('devices.store'), [
                'name' => 'Access Point Salah Ruangan',
                'building_id' => $building->id,
                'floor_id' => $firstFloor->id,
                'room_id' => $roomOnSecondFloor->id,
            ])
            ->assertSessionHasErrors('room_id');

        $this->assertSame(0, Device::count());
    }

    public function test_device_accepts_a_consistent_location_chain()
    {
        $user = User::factory()->create();
        $building = $this->building('GKB');
        $floor = $this->floor($building, 1);
        $room = Room::create(['floor_id' => $floor->id, 'name' => 'Ruang Server', 'code' => 'GKB-F1-RS']);
        $rack = Rack::create(['room_id' => $room->id, 'name' => 'Rak Utama', 'code' => 'GKB-F1-RS-RK01', 'capacity' => 42]);

        $this->actingAs($user)
            ->post(route('devices.store'), [
                'name' => 'Core Switch',
                'building_id' => $building->id,
                'floor_id' => $floor->id,
                'room_id' => $room->id,
                'rack_id' => $rack->id,
            ])
            ->assertSessionHasNoErrors();

        $device = Device::where('name', 'Core Switch')->firstOrFail();
        $this->assertSame($floor->id, $device->floor_id);
        $this->assertSame($room->id, $device->room_id);
        $this->assertSame($rack->id, $device->rack_id);
    }
}
