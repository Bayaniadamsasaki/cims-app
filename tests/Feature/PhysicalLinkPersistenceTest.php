<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\PhysicalLink;
use App\Services\PhysicalLinkPersistenceService;
use App\Services\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class PhysicalLinkPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_neighbor_persists_local_endpoint_provenance_and_last_seen(): void
    {
        $source = Device::factory()->create(['ip_address' => '10.0.0.1']);
        $target = Device::factory()->create(['ip_address' => '10.0.0.2']);
        $localInterface = DeviceInterface::factory()->create([
            'device_id' => $source->id,
            'interface_name' => 'ether5',
            'interface_status' => 'up',
        ]);
        $seenAt = now()->subMinute();

        app(PhysicalLinkPersistenceService::class)->persistNeighbors($source, [[
            'address' => $target->ip_address,
            'mac' => $target->mac_address,
            'identity' => 'RT-UTARA-02',
            'interface' => 'ether5',
            'platform' => 'MikroTik',
        ]], $seenAt);

        $link = PhysicalLink::firstOrFail();

        $this->assertSame($source->id, $link->device_a_id);
        $this->assertSame($localInterface->id, $link->interface_a_id);
        $this->assertSame($target->id, $link->device_b_id);
        $this->assertNull($link->interface_b_id);
        $this->assertSame('routeros_neighbor', $link->discovery_source);
        $this->assertSame('verified', $link->verification_status);
        $this->assertSame($seenAt->format('Y-m-d H:i:s'), $link->first_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame($seenAt->format('Y-m-d H:i:s'), $link->last_seen_at->format('Y-m-d H:i:s'));
        $this->assertSame('RT-UTARA-02', $link->metadata['remote_identity']);
    }

    public function test_reverse_discovery_does_not_duplicate_and_fills_the_other_local_interface(): void
    {
        $first = Device::factory()->create(['ip_address' => '10.0.0.1']);
        $second = Device::factory()->create(['ip_address' => '10.0.0.2']);
        $firstInterface = DeviceInterface::factory()->create([
            'device_id' => $first->id,
            'interface_name' => 'ether5',
        ]);
        $secondInterface = DeviceInterface::factory()->create([
            'device_id' => $second->id,
            'interface_name' => 'ether1',
        ]);
        $service = app(PhysicalLinkPersistenceService::class);

        $service->persistNeighbors($first, [[
            'address' => $second->ip_address,
            'mac' => $second->mac_address,
            'identity' => $second->name,
            'interface' => 'ether5',
        ]], now()->subMinutes(2));
        $service->persistNeighbors($second, [[
            'address' => $first->ip_address,
            'mac' => $first->mac_address,
            'identity' => $first->name,
            'interface' => 'ether1',
        ]], now());

        $this->assertSame(1, PhysicalLink::count());
        $link = PhysicalLink::firstOrFail();
        $this->assertSame($firstInterface->id, $link->interface_a_id);
        $this->assertSame($secondInterface->id, $link->interface_b_id);
        $this->assertTrue($link->first_seen_at->lessThan($link->last_seen_at));
    }

    public function test_discovery_does_not_delete_old_links_and_interface_down_is_not_relationship_deletion(): void
    {
        $source = Device::factory()->create(['ip_address' => '10.0.0.1']);
        $target = Device::factory()->create(['ip_address' => '10.0.0.2']);
        $interface = DeviceInterface::factory()->create([
            'device_id' => $source->id,
            'interface_name' => 'ether5',
            'interface_status' => 'down',
        ]);
        $seenAt = now()->subHour();

        app(PhysicalLinkPersistenceService::class)->persistNeighbors($source, [[
            'address' => $target->ip_address,
            'mac' => $target->mac_address,
            'interface' => $interface->interface_name,
        ]], $seenAt);

        $link = PhysicalLink::firstOrFail();
        $this->assertDatabaseHas('physical_links', ['id' => $link->id]);
        $this->assertSame('verified', $link->verification_status);
        $this->assertSame($seenAt->format('Y-m-d H:i:s'), $link->last_seen_at->format('Y-m-d H:i:s'));
    }

    public function test_topology_api_returns_persisted_physical_link_evidence(): void
    {
        $user = \App\Models\User::factory()->create();
        $source = Device::factory()->create(['ip_address' => '10.0.0.11']);
        $target = Device::factory()->create(['ip_address' => '10.0.0.12']);
        DeviceInterface::factory()->create([
            'device_id' => $source->id,
            'interface_name' => 'ether5',
            'interface_status' => 'up',
        ]);

        $this->mock(MikrotikService::class, function (MockInterface $mock) use ($target) {
            $mock->shouldReceive('testConnection')->once()->andReturn([
                'success' => true,
                'identity' => 'RT-UTARA-01',
                'board' => 'RB5009',
                'version' => '7.23',
            ]);
            $mock->shouldReceive('getNeighbors')->once()->andReturn([[
                'address' => $target->ip_address,
                'mac' => $target->mac_address,
                'identity' => 'RT-UTARA-02',
                'interface' => 'ether5',
            ]]);
            $mock->shouldReceive('getIpAddresses')->once()->andReturn([]);
        });

        $response = $this->actingAs($user)->getJson(route('topology.data', ['host' => $source->ip_address]));

        $response->assertOk()
            ->assertJsonPath('physical_links.0.source_interface', 'ether5')
            ->assertJsonPath('physical_links.0.target_interface', null)
            ->assertJsonPath('physical_links.0.verification_status', 'verified')
            ->assertJsonPath('physical_links.0.discovery_source', 'routeros_neighbor')
            ->assertJsonPath('physical_links.0.current_health', 'unknown');
    }

    public function test_neighbor_without_remote_identity_does_not_attach_to_an_arbitrary_device(): void
    {
        $source = Device::factory()->create();
        Device::factory()->create();

        app(PhysicalLinkPersistenceService::class)->persistNeighbors($source, [[
            'interface' => 'ether5',
            'address' => null,
            'mac' => null,
            'identity' => null,
        ]], now());

        $link = PhysicalLink::firstOrFail();
        $this->assertNull($link->device_b_id);
        $this->assertSame('routeros_neighbor', $link->discovery_source);
        $this->assertSame('verified', $link->verification_status);
    }
}
