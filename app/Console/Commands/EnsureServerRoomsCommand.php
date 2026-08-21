<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Room;

class EnsureServerRoomsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cims:ensure-server-rooms';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ensure every building has an automatically generated Ruang Server room on Floor 1';

    public function handle()
    {
        $buildings = Building::all();
        $this->info("Checking " . count($buildings) . " buildings for Ruang Server...");

        $createdCount = 0;
        foreach ($buildings as $building) {
            // 1. Ensure Floor level 1 exists
            $floor = Floor::firstOrCreate(
                ['building_id' => $building->id, 'level' => 1],
                ['name' => 'Lantai 1', 'description' => 'Lantai 1 ' . $building->name]
            );

            // 2. Check if a Ruang Server already exists for this building
            $existingServerRoom = Room::whereHas('floor', function ($q) use ($building) {
                $q->where('building_id', $building->id);
            })->where(function ($q) {
                $q->where('name', 'LIKE', '%Ruang Server%')
                  ->orWhere('name', 'LIKE', '%Server Room%')
                  ->orWhere('code', 'LIKE', '%RS%')
                  ->orWhere('code', 'LIKE', '%SERVER%');
            })->first();

            if (!$existingServerRoom) {
                $roomCode = strtoupper($building->code) . '-F1-RS';
                
                // Make code unique if needed
                $counter = 1;
                $originalCode = $roomCode;
                while (Room::where('code', $roomCode)->exists()) {
                    $roomCode = $originalCode . '-' . $counter;
                    $counter++;
                }

                Room::create([
                    'floor_id' => $floor->id,
                    'name' => 'Ruang Server',
                    'code' => $roomCode,
                    'description' => 'Ruang Server & Core Network ' . $building->name,
                ]);

                $createdCount++;
                $this->info(" -> Added 'Ruang Server' to Gedung: {$building->name} ({$building->code})");
            }

            // Update rooms_count
            $building->update([
                'rooms_count' => Room::whereHas('floor', fn($q) => $q->where('building_id', $building->id))->count()
            ]);
        }

        $this->info("Done! Successfully ensured all buildings have a Ruang Server ({$createdCount} new rooms created).");
        return 0;
    }
}
