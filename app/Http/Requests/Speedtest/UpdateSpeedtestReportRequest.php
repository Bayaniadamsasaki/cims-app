<?php

namespace App\Http\Requests\Speedtest;

class UpdateSpeedtestReportRequest extends StoreSpeedtestReportRequest
{
    /**
     * Aturan sama dengan store, ditambah flag untuk menghapus screenshot lama
     * tanpa harus mengunggah gambar pengganti.
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'remove_screenshot' => ['nullable', 'boolean'],
        ]);
    }
}
