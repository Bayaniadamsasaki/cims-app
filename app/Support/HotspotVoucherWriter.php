<?php

namespace App\Support;

use App\Models\HotspotVoucher;

/**
 * Penulis baris voucher yang dipakai bersama oleh import Excel/CSV dan
 * sinkronisasi SISKA.
 *
 * Yang dikumpulkan di sini adalah aturan yang mudah menyimpang kalau disalin
 * dua kali: satu voucher per NIM, dan setiap perubahan kredensial mengembalikan
 * status ke pending supaya isi RADIUS tidak pernah lebih tua dari isi database.
 *
 * Kuncinya NIM saja, bukan lagi (NIM, router): yang menjawab Access-Request untuk
 * seluruh router hotspot kampus hanya satu server RADIUS, jadi satu mahasiswa
 * cukup punya satu identitas. router_host tetap diisi sebagai catatan router yang
 * dipakai saat baris ini dibuat.
 */
class HotspotVoucherWriter
{
    /**
     * NIM yang boleh menjadi username hotspot. RouterOS menolak spasi dan
     * karakter aneh, jadi barisnya lebih baik dilewati daripada gagal saat push.
     */
    public const NIM_PATTERN = '/^[A-Za-z0-9._-]{3,64}$/';

    /**
     * Voucher yang tadinya dimatikan otomatis lalu muncul lagi di sumber data.
     * Dihitung di sini karena hanya upsert() yang bisa melihatnya, sementara yang
     * perlu memberi tahu operator — barisnya kembali pending dan menunggu
     * diterapkan ke RADIUS — adalah pemanggilnya.
     */
    public int $revived = 0;

    public function __construct(
        protected string $routerHost,
        protected ?string $defaultProfile = null,
        protected ?string $defaultServer = null,
        protected ?string $batchLabel = null,
        protected ?int $userId = null,
        protected ?string $validUntil = null,
        protected string $source = HotspotVoucher::SOURCE_MANUAL,
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
        $voucher = HotspotVoucher::firstOrNew(['nim' => $nim]);

        $wasExisting = $voucher->exists;

        $voucher->fill([
            'student_name' => $data['student_name'] ?? null,
            'program' => $data['program'] ?? null,
            'faculty' => $data['faculty'] ?? null,
            'password' => $data['password'],
            'profile' => $data['profile'] ?? $this->defaultProfile,
            'server' => $this->defaultServer,
            'router_host' => $this->routerHost,
            'comment' => $data['comment'] ?? null,
            'valid_until' => $data['valid_until'] ?? $this->validUntil,
            'batch_label' => $this->batchLabel,
            'created_by' => $voucher->created_by ?? $this->userId,

            // Asal baris hanya boleh naik ke 'pmb', tidak pernah turun. PMB yang
            // menyebut sebuah NIM berarti ia memang mahasiswa, dan sejak itu
            // barisnya boleh dinonaktifkan otomatis saat NIM-nya hilang dari PMB.
            // Sebaliknya, unggahan Excel berisi NIM yang sama tidak boleh
            // mencabut hak itu.
            'source' => $wasExisting && $this->source !== HotspotVoucher::SOURCE_PMB
                ? ($voucher->source ?: $this->source)
                : $this->source,
        ]);

        // Mahasiswa yang pernah hilang dari PMB lalu muncul lagi (selesai cuti,
        // aktivasi ulang) harus hidup kembali meski kredensialnya tidak berubah;
        // tanpa ini statusnya menempel di disabled selamanya. Hanya berlaku untuk
        // baris yang dimatikan otomatis — disabled_reason terisi — supaya keputusan
        // operator lewat tombol Blokir tidak ikut dibatalkan oleh sinkronisasi.
        $revived = $wasExisting
            && $this->source === HotspotVoucher::SOURCE_PMB
            && $voucher->getOriginal('status') === HotspotVoucher::STATUS_DISABLED
            && filled($voucher->getOriginal('disabled_reason'));

        if ($revived) {
            $this->revived++;
        }

        // Perubahan kredensial wajib diterapkan ulang ke RADIUS; pesan gagal yang
        // lama ikut dibersihkan supaya tidak menempel pada data yang sudah benar.
        // 'server' tidak ikut dilihat: itu nama hotspot server RouterOS, tidak
        // punya padanan di RADIUS, jadi mengubahnya tidak membuat baris basi.
        if (! $wasExisting || $revived || $voucher->isDirty(['password', 'profile', 'limit_uptime'])) {
            $voucher->status = HotspotVoucher::STATUS_PENDING;
            $voucher->last_error = null;
            $voucher->disabled_reason = null;
        }

        $voucher->save();

        return $voucher;
    }

    public function routerHost(): string
    {
        return $this->routerHost;
    }
}
