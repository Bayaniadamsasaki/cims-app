<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu lantai hanya boleh muncul sekali per gedung.
     *
     * Lantai duplikat (building_id + level yang sama) digabung dulu ke lantai
     * dengan id terkecil sebelum unique index dipasang. Penggabungan dilakukan
     * dengan memindahkan anak-anaknya, bukan menghapusnya: rooms.floor_id
     * memakai cascadeOnDelete dan devices.floor_id memakai nullOnDelete, jadi
     * menghapus lantai duplikat lebih dulu akan ikut membuang ruangan dan
     * memutus penempatan perangkat.
     */
    public function up(): void
    {
        $this->mergeDuplicateFloors();

        Schema::table('floors', function (Blueprint $table) {
            $table->unique(['building_id', 'level'], 'floors_building_id_level_unique');
        });
    }

    public function down(): void
    {
        Schema::table('floors', function (Blueprint $table) {
            $table->dropUnique('floors_building_id_level_unique');
        });
    }

    private function mergeDuplicateFloors(): void
    {
        $duplicateGroups = DB::table('floors')
            ->select('building_id', 'level', DB::raw('MIN(id) as keep_id'))
            ->groupBy('building_id', 'level')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $duplicateIds = DB::table('floors')
                ->where('building_id', $group->building_id)
                ->where('level', $group->level)
                ->where('id', '!=', $group->keep_id)
                ->pluck('id')
                ->all();

            if ($duplicateIds === []) {
                continue;
            }

            DB::table('rooms')
                ->whereIn('floor_id', $duplicateIds)
                ->update(['floor_id' => $group->keep_id]);

            if (Schema::hasColumn('devices', 'floor_id')) {
                DB::table('devices')
                    ->whereIn('floor_id', $duplicateIds)
                    ->update(['floor_id' => $group->keep_id]);
            }

            DB::table('floors')->whereIn('id', $duplicateIds)->delete();
        }
    }
};
