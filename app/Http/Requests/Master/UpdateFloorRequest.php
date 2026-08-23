<?php

namespace App\Http\Requests\Master;

use App\Models\Floor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFloorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $floorId = $this->route('floor');

        // `building_id` boleh tidak dikirim saat update; kalau begitu level tetap
        // diperiksa terhadap gedung tempat lantai ini berada sekarang.
        $buildingId = $this->input('building_id')
            ?? Floor::whereKey($floorId)->value('building_id');

        return [
            'building_id' => ['sometimes', 'required', 'exists:buildings,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'level' => [
                'sometimes',
                'required',
                'integer',
                Rule::unique('floors', 'level')
                    ->where(fn ($query) => $query->where('building_id', $buildingId))
                    ->ignore($floorId),
            ],
            'description' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'level.unique' => 'Level lantai ini sudah terdaftar pada gedung yang dipilih.',
        ];
    }
}
