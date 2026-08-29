<?php

namespace App\Support;

use App\Models\HotspotVoucher;

/**
 * Penulis baris voucher yang dipakai bersama oleh import Excel/CSV dan
 * sinkronisasi SISKA.
 *
 * Yang dikumpulkan di sini adalah aturan yang mudah menyimpang kalau disalin
 * dua kali: satu voucher per (NIM, router), dan setiap perubahan kredensial
 * mengembalikan status ke pending supaya isi router tidak pernah lebih tua
 * dari isi database.
 */
class HotspotVoucherWriter
{
    /**
     * NIM yang boleh menjadi username hotspot. RouterOS menolak spasi dan
     * karakter aneh, jadi barisnya lebih baik dilewati daripada gagal saat push.
     */
    public const NIM_PATTERN = '/^[A-Za-z0-9._-]{3,64}$/';

    public function __construct(
        protected string $routerHost,
        protected ?string $defaultProfile = null,
        protected ?string $defaultServer = null,
        protected ?string $batchLabel = null,
        protected ?int $userId = null,
        protected ?string $validUntil = null,
    ) {
    }

    /**
     * Simpan satu voucher. Pakai $voucher->wasRecentlyCreated pada hasilnya
     * untuk memisahkan baris baru dari baris yang diperbarui.
     *
     * @param  array{student_name?:?string,program?:?string,faculty?:?string,password:string,profile?:?string,comment?:?string,valid_until?:?string}  $data
     */
    public function upsert(string $nim, array $data): HotspotVoucher
    {
        $voucher = HotspotVoucher::firstOrNew([
            'nim' => $nim,
            'router_host' => $this->routerHost,
        ]);

        $wasExisting = $voucher->exists;

        $voucher->fill([
            'student_name' => $data['student_name'] ?? null,
            'program' => $data['program'] ?? null,
            'faculty' => $data['faculty'] ?? null,
            'password' => $data['password'],
            'profile' => $data['profile'] ?? $this->defaultProfile,
            'server' => $this->defaultServer,
            'comment' => $data['comment'] ?? null,
            'valid_until' => $data['valid_until'] ?? $this->validUntil,
            'batch_label' => $this->batchLabel,
            'created_by' => $voucher->created_by ?? $this->userId,
        ]);

        // Perubahan kredensial wajib dikirim ulang ke router; pesan gagal yang
        // lama ikut dibersihkan supaya tidak menempel pada data yang sudah benar.
        if (! $wasExisting || $voucher->isDirty(['password', 'profile', 'server'])) {
            $voucher->status = HotspotVoucher::STATUS_PENDING;
            $voucher->last_error = null;
        }

        $voucher->save();

        return $voucher;
    }

    public function routerHost(): string
    {
        return $this->routerHost;
    }
}
