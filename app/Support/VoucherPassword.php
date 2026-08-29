<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Satu-satunya tempat aturan password voucher hotspot ditulis.
 *
 * Mahasiswa diberi tahu passwordnya adalah tanggal lahir dengan urutan
 * tanggal-bulan-tahun, sementara SISKA menyimpannya terbalik (Y-m-d) dan untuk
 * sebagian mahasiswa kolomnya kosong. Dua-duanya diselesaikan di sini supaya
 * perintah sinkronisasi, import Excel, dan halaman voucher tidak sempat punya
 * versi aturan masing-masing.
 *
 * Tanggal lahir yang kosong tidak menghalangi vouchernya dibuat: passwordnya
 * memakai NIM, dan hasilnya ({@see usesBirthDate()}) ikut dilaporkan supaya
 * operator tahu mahasiswa mana yang harus diberi tahu passwordnya berbeda.
 */
final class VoucherPassword
{
    public const SOURCE_BIRTH_DATE = 'tanggal_lahir';

    public const SOURCE_NIM = 'nim';

    /** Tahun lahir tertua yang masih wajar untuk mahasiswa aktif. */
    protected const MIN_YEAR = 1940;

    /**
     * Umur minimal supaya tanggal lahir dianggap benar. SISKA memuat beberapa
     * baris bertahun berjalan/berikutnya — itu salah entri, bukan tanggal lahir,
     * dan passwordnya tidak boleh ikut salah.
     */
    protected const MIN_AGE_YEARS = 10;

    private function __construct(
        public readonly string $value,
        public readonly string $source,
    ) {
    }

    /**
     * Password untuk satu mahasiswa: tanggal lahir bila ada dan wajar, kalau
     * tidak NIM-nya sendiri supaya vouchernya tetap bisa dipakai.
     */
    public static function forStudent(string $nim, ?string $birthDate, ?string $format = null): self
    {
        $fromBirthDate = self::formatBirthDate($birthDate, $format);

        return $fromBirthDate !== null
            ? new self($fromBirthDate, self::SOURCE_BIRTH_DATE)
            : new self($nim, self::SOURCE_NIM);
    }

    /**
     * Tanggal lahir apa pun bentuknya menjadi deretan angka sesuai format .env,
     * atau null bila kosong, tidak terbaca, atau tidak wajar.
     *
     * Sengaja lewat objek tanggal, bukan potong-tempel string: "1988-05-30"
     * dan "30/05/1988" harus menghasilkan password yang sama persis.
     */
    public static function formatBirthDate(?string $birthDate, ?string $format = null): ?string
    {
        $birthDate = trim((string) $birthDate);

        // "0000-00-00" adalah tanggal kosong milik MySQL, bukan tahun nol.
        if ($birthDate === '' || str_starts_with($birthDate, '0000')) {
            return null;
        }

        try {
            $date = CarbonImmutable::parse($birthDate);
        } catch (\Throwable) {
            return null;
        }

        if ($date->year < self::MIN_YEAR) {
            return null;
        }

        if ($date->isAfter(CarbonImmutable::now()->subYears(self::MIN_AGE_YEARS))) {
            return null;
        }

        return $date->format($format ?: self::format());
    }

    /** Passwordnya benar-benar berasal dari tanggal lahir, bukan NIM. */
    public function usesBirthDate(): bool
    {
        return $this->source === self::SOURCE_BIRTH_DATE;
    }

    /** Format tanggal dari .env, dengan dmY sebagai jaring aman. */
    protected static function format(): string
    {
        $format = trim((string) config('services.hotspot.password_format'));

        return $format !== '' ? $format : 'dmY';
    }
}
