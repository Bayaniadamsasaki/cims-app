<?php

namespace Tests\Unit;

use App\Services\TopologyOverviewService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class TopologyOverviewServiceTest extends TestCase
{
    public function test_it_groups_real_locations_and_keeps_unknown_telemetry_explicit(): void
    {
        $overview = (new TopologyOverviewService())->build(new Collection(), [
            [
                'id' => 'db-device-1',
                'db_id' => 1,
                'name' => 'RT-Utara-01',
                'type' => 'router',
                'status' => 'online',
                'building' => 'Gedung Utara',
            ],
            [
                'id' => 'discovered-neighbor-0',
                'name' => 'Neighbor-01',
                'type' => 'switch',
                'status' => 'unknown',
                'building' => 'Terdeteksi via ether2',
            ],
            [
                'id' => 'db-device-2',
                'db_id' => 2,
                'name' => 'AP-Utara-01',
                'type' => 'access_point',
                'status' => 'unreachable',
                'building' => 'Gedung Utara',
                'room' => 'Lantai 3',
                'last_checked_at' => '2026-09-08T10:00:00+00:00',
            ],
        ], [
            ['id' => 'verified', 'source' => 'a', 'target' => 'b', 'inferred' => false],
            ['id' => 'inferred', 'source' => 'a', 'target' => 'c', 'inferred' => true],
        ]);

        $this->assertSame(1, $overview['health']['healthy']);
        $this->assertSame(1, $overview['health']['down']);
        $this->assertSame(1, $overview['health']['unknown']);
        $this->assertSame(1, $overview['active_incidents']);
        $this->assertSame(1, $overview['evidence_scope']['relationships']);
        $this->assertSame(1, $overview['evidence_scope']['inferred_relationships']);

        $north = collect($overview['locations'])->firstWhere('name', 'Gedung Utara');

        $this->assertSame(2, $north['total_devices']);
        $this->assertSame(1, $north['healthy']);
        $this->assertSame(1, $north['down']);
        $this->assertSame('Gedung Utara · Lantai 3', $overview['incidents'][0]['location']);
        $this->assertNull($overview['incidents'][0]['probable_root_cause']);
    }

    public function test_it_does_not_create_incidents_for_unknown_or_online_nodes(): void
    {
        $overview = (new TopologyOverviewService())->build(new Collection(), [
            ['id' => 'online', 'name' => 'Online', 'type' => 'router', 'status' => 'online'],
            ['id' => 'unknown', 'name' => 'Unknown', 'type' => 'router', 'status' => 'unknown'],
        ], []);

        $this->assertSame(0, $overview['active_incidents']);
        $this->assertSame(1, $overview['health']['healthy']);
        $this->assertSame(1, $overview['health']['unknown']);
    }

    public function test_it_only_marks_down_downstream_devices_as_affected_on_verified_links(): void
    {
        $overview = (new TopologyOverviewService())->build(new Collection(), [
            ['id' => 'parent', 'name' => 'RT-01', 'type' => 'router', 'status' => 'unreachable'],
            ['id' => 'child', 'db_id' => 22, 'name' => 'AP-01', 'type' => 'access_point', 'status' => 'unreachable'],
            ['id' => 'unknown-child', 'db_id' => 23, 'name' => 'AP-02', 'type' => 'access_point', 'status' => 'unknown'],
        ], [
            ['id' => 'verified', 'source' => 'parent', 'target' => 'child', 'inferred' => false],
            ['id' => 'inferred', 'source' => 'parent', 'target' => 'unknown-child', 'inferred' => true],
        ]);

        $this->assertSame(1, $overview['affected_devices']);
        $this->assertSame('AP-01', $overview['incidents'][0]['affected_devices'][0]['name']);
        $this->assertStringContainsString('parent/uplink', $overview['incidents'][0]['probable_root_cause']);
    }
}
