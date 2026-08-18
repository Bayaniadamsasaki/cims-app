<?php

namespace App\Http\Requests\Speedtest;

use App\Models\SpeedtestReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSpeedtestReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tested_at' => ['required', 'date'],
            'location' => ['required', 'string', 'max:255'],
            'ssid' => ['required', 'string', 'max:255'],
            'download_mbps' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'upload_mbps' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'ping_ms' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'status' => ['required', Rule::in(array_keys(SpeedtestReport::STATUSES))],
            'device_type' => ['required', Rule::in(array_keys(SpeedtestReport::DEVICE_TYPES))],
            'tester_id' => ['required', 'exists:speedtest_testers,id'],
            'action' => ['required', Rule::in(array_keys(SpeedtestReport::ACTIONS))],
            'screenshot' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    public function attributes(): array
    {
        return [
            'tested_at' => 'Tanggal Action',
            'location' => 'Lokasi',
            'ssid' => 'SSID',
            'download_mbps' => 'Download (Mbps)',
            'upload_mbps' => 'Upload (Mbps)',
            'ping_ms' => 'Ping (ms)',
            'status' => 'Status Action',
            'device_type' => 'Perangkat Uji Coba',
            'tester_id' => 'Penguji',
            'action' => 'Tindakan / Action',
            'screenshot' => 'Bukti Screenshot',
        ];
    }

    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'numeric' => ':attribute harus berupa angka.',
            'min' => ':attribute tidak boleh kurang dari :min.',
            'date' => ':attribute harus berupa tanggal dan waktu yang valid.',
            'in' => 'Pilihan :attribute tidak valid.',
            'exists' => ':attribute yang dipilih tidak terdaftar.',
            'screenshot.image' => 'Bukti Screenshot harus berupa file gambar.',
            'screenshot.mimes' => 'Bukti Screenshot hanya menerima format JPG, JPEG, PNG, atau WEBP.',
            'screenshot.max' => 'Ukuran Bukti Screenshot maksimal 4 MB.',
        ];
    }
}
