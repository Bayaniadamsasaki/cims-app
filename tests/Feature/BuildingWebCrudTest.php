<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class BuildingWebCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_building_can_be_registered_without_floor_or_room_counts()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('buildings.store'), [
            'name' => 'Gedung Kuliah Bersama A',
            'code' => 'GKB-A',
            'description' => 'Gedung utama perkuliahan.',
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();

        $building = Building::where('code', 'GKB-A')->firstOrFail();
        $this->assertSame('Gedung Kuliah Bersama A', $building->name);
    }

    public function test_registering_a_building_creates_floor_one_with_a_server_room()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('buildings.store'), [
            'name' => 'Gedung Rektorat',
            'code' => 'REK',
            'description' => null,
        ])->assertSessionHasNoErrors();

        $building = Building::where('code', 'REK')->firstOrFail();

        $floors = Floor::where('building_id', $building->id)->get();
        $this->assertCount(1, $floors);
        $this->assertSame(1, (int) $floors->first()->level);

        $rooms = Room::where('floor_id', $floors->first()->id)->get();
        $this->assertCount(1, $rooms);
        $this->assertSame('Ruang Server', $rooms->first()->name);
        $this->assertSame('REK-F1-RS', $rooms->first()->code);

        $this->assertSame(1, (int) $building->fresh()->rooms_count);
    }

    public function test_index_reports_floor_and_room_totals_from_actual_records()
    {
        $user = User::factory()->create();

        $building = Building::create([
            'name' => 'Gedung Laboratorium',
            'code' => 'LAB',
            'floors_count' => 1,
            'rooms_count' => 0,
        ]);

        // Struktur ditambahkan lewat menu Floors/Rooms, tanpa menyentuh kolom counter.
        foreach ([1, 2, 3] as $level) {
            $floor = Floor::create([
                'building_id' => $building->id,
                'name' => 'Lantai ' . $level,
                'level' => $level,
            ]);

            Room::create([
                'floor_id' => $floor->id,
                'name' => 'Ruangan ' . $level,
                'code' => 'LAB-F' . $level . '-R01',
            ]);
        }

        $this->actingAs($user)
            ->get(route('buildings.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Master/Buildings')
                ->where('buildings.0.code', 'LAB')
                ->where('buildings.0.floors_count', 3)
                ->where('buildings.0.rooms_count', 3)
            );
    }

    public function test_building_code_must_be_unique()
    {
        $user = User::factory()->create();

        Building::create(['name' => 'Gedung A', 'code' => 'GDA']);

        $this->actingAs($user)
            ->post(route('buildings.store'), ['name' => 'Gedung A Duplikat', 'code' => 'GDA'])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, Building::where('code', 'GDA')->count());
    }
}
