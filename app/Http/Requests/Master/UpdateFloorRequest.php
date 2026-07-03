<?php

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFloorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'building_id' => ['sometimes', 'required', 'exists:buildings,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'level' => ['sometimes', 'required', 'integer'],
            'description' => ['nullable', 'string'],
        ];
    }
}
