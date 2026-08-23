<?php

namespace App\Http\Requests\Concerns;

use App\Models\Floor;
use App\Models\Rack;
use App\Models\Room;
use Illuminate\Validation\Validator;

/**
 * Lokasi perangkat mengikuti hierarki Building → Floor → Room → Rack. Rule
 * `exists` hanya memastikan id-nya ada, bukan bahwa keempatnya berada di cabang
 * yang sama — jadi rantainya diperiksa sekali lagi di sini: setiap tingkat harus
 * benar-benar berada di dalam tingkat di atasnya.
 *
 * Tingkat yang tidak dikirim dilewati, karena penempatan perangkat boleh berhenti
 * di tengah hierarki (mis. hanya sampai lantai, belum ditentukan ruangannya).
 */
trait ValidatesLocationChain
{
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Id yang sudah gagal rule `exists` tidak perlu diperiksa rantainya.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->assertLocationChain($validator);
        });
    }

    private function assertLocationChain(Validator $validator): void
    {
        $buildingId = $this->input('building_id');
        $floorId = $this->input('floor_id');
        $roomId = $this->input('room_id');
        $rackId = $this->input('rack_id');

        if ($floorId && $buildingId && ! $this->isChild(Floor::class, $floorId, 'building_id', $buildingId)) {
            $validator->errors()->add('floor_id', 'Lantai yang dipilih bukan bagian dari gedung tersebut.');
        }

        if ($roomId && $floorId && ! $this->isChild(Room::class, $roomId, 'floor_id', $floorId)) {
            $validator->errors()->add('room_id', 'Ruangan yang dipilih bukan bagian dari lantai tersebut.');
        }

        if ($rackId && $roomId && ! $this->isChild(Rack::class, $rackId, 'room_id', $roomId)) {
            $validator->errors()->add('rack_id', 'Rak yang dipilih bukan bagian dari ruangan tersebut.');
        }
    }

    private function isChild(string $model, $id, string $parentColumn, $parentId): bool
    {
        return $model::where('id', $id)->where($parentColumn, $parentId)->exists();
    }
}
