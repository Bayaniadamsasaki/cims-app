<?php

namespace App\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;

class StoreDeviceInterfaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => ['required', 'exists:devices,id'],
            'interface_name' => ['required', 'string', 'max:255'],
            'ip_address' => ['nullable', 'ip'],
            'subnet' => ['nullable', 'string', 'max:50'],
            'gateway' => ['nullable', 'ip'],
            'mac_address' => ['nullable', 'string', 'max:17', 'regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/'],
            'interface_type' => ['nullable', 'string', 'max:50'],
            'interface_status' => ['nullable', 'string', 'in:up,down,disabled'],
            'speed' => ['nullable', 'string', 'max:50'],
            'mtu' => ['nullable', 'integer', 'min:68', 'max:9216'],
            'description' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'mac_address.regex' => 'The MAC address must be in format XX:XX:XX:XX:XX:XX or XX-XX-XX-XX-XX-XX.',
        ];
    }
}
