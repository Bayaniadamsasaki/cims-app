<?php

namespace App\Support;

use App\Models\Device;

/**
 * Satu-satunya tempat password perangkat jaringan didekripsi.
 *
 * Sebelumnya {@see Device} punya accessor `password_plain` yang ikut di-`$appends`,
 * jadi setiap perangkat yang di-serialize — props Inertia halaman inventaris,
 * `DeviceResource` di API — membawa password routernya dalam bentuk plaintext
 * sampai ke browser. Dekripsi dipindahkan ke sini supaya hanya kode yang
 * benar-benar membuka koneksi ke perangkat (RouterOS API) yang memegang
 * nilainya, dan tidak ada jalur serialisasi yang bisa membocorkannya lagi tanpa
 * sengaja.
 *
 * Jangan menambahkan accessor plaintext baru di model Device: pemanggil harus
 * lewat kelas ini supaya titik keluarnya kredensial tetap satu dan bisa diaudit.
 */
final class DeviceCredential
{
    private function __construct()
    {
    }

    /**
     * Password perangkat siap pakai untuk login, atau null bila perangkatnya
     * tidak punya kredensial tersimpan.
     *
     * Sebagian baris hasil import lama tersimpan tanpa enkripsi. Nilai yang
     * gagal didekripsi dikembalikan apa adanya supaya koneksi ke perangkat itu
     * tetap jalan — kalau tidak, menutup kebocoran ini justru akan mematikan
     * monitoring perangkat yang datanya paling tua.
     */
    public static function password(Device $device): ?string
    {
        $stored = self::stored($device);

        if (blank($stored)) {
            return null;
        }

        try {
            return decrypt($stored);
        } catch (\Throwable) {
            return $stored;
        }
    }

    /** Perangkat ini punya kredensial tersimpan atau tidak. */
    public static function exists(Device $device): bool
    {
        return filled(self::stored($device));
    }

    /**
     * Ciphertext mentah dari kolom, dibaca lewat `getAttributes()` supaya tidak
     * bergantung pada accessor/`$hidden` model yang bisa berubah.
     */
    private static function stored(Device $device): ?string
    {
        return $device->getAttributes()['password_encrypted'] ?? null;
    }
}
