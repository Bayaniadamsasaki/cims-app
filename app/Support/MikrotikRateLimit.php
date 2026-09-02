<?php

namespace App\Support;

/**
 * Penerjemah dua arah antara atribut `Mikrotik-Rate-Limit` dan dua angka Mbps
 * yang diisi operator di halaman Paket Hotspot.
 *
 * Ada satu hal yang membuat kelas ini perlu ada, dan bukan sekadar sprintf:
 * urutan atributnya `rx/tx` DARI SUDUT PANDANG ROUTER. rx adalah yang diterima
 * router dari klien — yaitu UNGGAH mahasiswa — dan tx adalah yang dikirim router
 * ke klien, yaitu UNDUH. Jadi paket "unduh 8 Mbps, unggah 2 Mbps" ditulis
 * `2M/8M`, bukan `8M/2M`.
 *
 * Tertukar tidak memunculkan error apa pun: FreeRADIUS mengirimkannya, MikroTik
 * menerimanya, dan mahasiswa cuma merasa internet lambat sementara grafik router
 * terlihat wajar. Karena itu satu-satunya tempat urutan itu ditentukan adalah di
 * sini, dan ada test yang memakunya.
 *
 * Bentuk lanjut atribut ini — burst, threshold, priority — memakai spasi:
 *
 *     rx/tx  rx-burst/tx-burst  rx-threshold/tx-threshold  burst-time  priority
 *
 * Nilai seperti itu TIDAK dibongkar menjadi dua angka. parse() mengembalikan
 * null, dan halaman menampilkannya sebagai teks apa adanya supaya nilai yang
 * pernah disetel dengan tangan tidak rusak hanya karena seseorang membuka
 * formulirnya.
 */
class MikrotikRateLimit
{
    /** Satu angka + satuan opsional: '2M', '512k', '1.5M', atau bps polos. */
    protected const RATE = '/^(\d+(?:\.\d+)?)([kKmMgG]?)$/';

    /**
     * Dua angka Mbps dari bentuk sederhana `rx/tx`, atau null bila bukan.
     *
     * @return array{upload:float,download:float}|null
     */
    public static function parse(?string $value): ?array
    {
        $value = trim((string) $value);

        // Spasi berarti bentuk lanjut (burst dst). Jangan coba diterjemahkan:
        // menampilkannya sebagai dua angka akan membuang bagian yang lain saat
        // formulirnya disimpan kembali.
        if ($value === '' || str_contains($value, ' ')) {
            return null;
        }

        $parts = explode('/', $value);

        if (count($parts) !== 2) {
            return null;
        }

        $upload = self::toMbps($parts[0]);
        $download = self::toMbps($parts[1]);

        if ($upload === null || $download === null) {
            return null;
        }

        return ['upload' => $upload, 'download' => $download];
    }

    /**
     * Rakit atribut dari dua angka Mbps. Urutannya unggah dulu — lihat docblock
     * kelas ini sebelum menukarnya.
     */
    public static function format(float $upload, float $download): string
    {
        return self::rate($upload).'/'.self::rate($download);
    }

    /** Mbps → token RouterOS. Bilangan bulat jadi 'M', pecahan jadi 'k'. */
    protected static function rate(float $mbps): string
    {
        if ($mbps === floor($mbps)) {
            return ((int) $mbps).'M';
        }

        return ((int) round($mbps * 1000)).'k';
    }

    /** Token RouterOS → Mbps. Angka tanpa satuan dihitung sebagai bit/detik. */
    protected static function toMbps(string $token): ?float
    {
        if (! preg_match(self::RATE, trim($token), $m)) {
            return null;
        }

        $number = (float) $m[1];

        $mbps = match (strtolower($m[2])) {
            'g' => $number * 1000,
            'm' => $number,
            'k' => $number / 1000,
            default => $number / 1000000,
        };

        return $mbps > 0 ? round($mbps, 3) : null;
    }
}
