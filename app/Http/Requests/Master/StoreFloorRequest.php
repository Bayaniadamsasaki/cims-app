<?php

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFloorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'building_id' => ['required', 'exists:buildings,id'],
            'name' => ['required', 'string', 'max:255'],
            // Level unik di dalam gedungnya — aturan yang sama dijaga database
            // lewat UNIQUE(building_id, level), jadi di sini dicegah lebih awal
            // supaya klien menerima 422 dan bukan error query.
            'level' => [
                'required',
                'integer',
                Rule::unique('floors', 'level')->where(
                    fn ($query) => $query->where('building_id', $this->input('building_id'))
                ),
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
