<?php

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $buildingId = $this->route('building');
        if (is_object($buildingId)) {
            $buildingId = $buildingId->id;
        }

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50', 'unique:buildings,code,' . $buildingId],
            'description' => ['nullable', 'string'],
        ];
    }
}
