<?php

namespace App\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'ip_address' => ['nullable', 'ip'],
            'mac_address' => ['nullable', 'regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/'],
            'vendor_id' => ['nullable', 'exists:vendors,id'],
            'device_category_id' => ['nullable', 'exists:device_categories,id'],
            'model' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'firmware' => ['nullable', 'string', 'max:255'],
            'purchase_date' => ['nullable', 'date'],
            'warranty' => ['nullable', 'string', 'max:255'],
            'building_id' => ['nullable', 'exists:buildings,id'],
            'floor_id' => ['nullable', 'exists:floors,id'],
            'room_id' => ['nullable', 'exists:rooms,id'],
            'rack_id' => ['nullable', 'exists:racks,id'],
            'status' => ['nullable', 'string', 'in:active,maintenance,inactive,offline,online'],
            'notes' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ];
    }
}
