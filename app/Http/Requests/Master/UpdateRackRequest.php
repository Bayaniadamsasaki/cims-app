<?php

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'room_id' => ['sometimes', 'required', 'exists:rooms,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'code' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
        ];
    }
}
