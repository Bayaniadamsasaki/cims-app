<?php

namespace Tests\Unit;

use App\Support\MikrotikRateLimit;
use PHPUnit\Framework\TestCase;

/**
 * Urutan rx/tx pada atribut Mikrotik-Rate-Limit.
 *
 * Test ini ada karena tertukarnya urutan tidak memunculkan error di mana pun:
 * FreeRADIUS mengirim atributnya, MikroTik menerimanya, dan satu-satunya gejala
 * adalah mahasiswa merasa unduhannya lambat sementara grafik router terlihat
 * wajar. Tidak ada log yang bisa dibaca untuk menemukannya, jadi urutannya
 * dipatok di sini.
 *
 * rx = yang DITERIMA router dari klien  = unggah mahasiswa  = ruas kiri
 * tx = yang DIKIRIM router ke klien     = unduh mahasiswa   = ruas kanan
 */
class MikrotikRateLimitTest extends TestCase
{
    public function test_upload_comes_first_because_rx_is_the_routers_point_of_view(): void
    {
        // Paket "unduh 8 Mbps, unggah 2 Mbps" — bukan 8M/2M.
        $this->assertSame('2M/8M', MikrotikRateLimit::format(2, 8));
    }

    public function test_a_simple_value_parses_back_into_the_same_two_numbers(): void
    {
        $this->assertSame(
            ['upload' => 2.0, 'download' => 8.0],
            MikrotikRateLimit::parse('2M/8M'),
        );
    }

    public function test_formatting_then_parsing_returns_what_was_put_in(): void
    {
        $parsed = MikrotikRateLimit::parse(MikrotikRateLimit::format(1.5, 20));

        $this->assertSame(1.5, $parsed['upload']);
        $this->assertSame(20.0, $parsed['download']);
    }

    public function test_a_fraction_of_a_megabit_becomes_kilobits(): void
    {
        // RouterOS menerima '512k'; '0.512M' bukan bentuk yang lazim ditulis.
        $this->assertSame('512k/1M', MikrotikRateLimit::format(0.512, 1));
    }

    public function test_kilobit_and_gigabit_units_are_understood(): void
    {
        $this->assertSame(
            ['upload' => 0.512, 'download' => 1000.0],
            MikrotikRateLimit::parse('512k/1G'),
        );
    }

    public function test_a_bare_number_is_read_as_bits_per_second(): void
    {
        // 1000000 bps = 1 Mbps. Nilai lama dari router kadang ditulis begini.
        $this->assertSame(
            ['upload' => 1.0, 'download' => 2.0],
            MikrotikRateLimit::parse('1000000/2000000'),
        );
    }

    /**
     * Bentuk lanjut (burst, threshold, priority) TIDAK boleh diurai jadi dua
     * angka: formulir kecepatan yang menyimpannya kembali akan membuang bagian
     * yang lain. null di sini adalah yang membuat halaman menampilkannya sebagai
     * teks apa adanya.
     */
    public function test_a_value_with_bursts_is_left_alone(): void
    {
        $this->assertNull(MikrotikRateLimit::parse('2M/8M 4M/16M 3M/12M 8 8'));
    }

    public function test_a_value_that_is_not_two_rates_is_rejected(): void
    {
        $this->assertNull(MikrotikRateLimit::parse(''));
        $this->assertNull(MikrotikRateLimit::parse(null));
        $this->assertNull(MikrotikRateLimit::parse('8M'));
        $this->assertNull(MikrotikRateLimit::parse('2M/8M/1M'));
        $this->assertNull(MikrotikRateLimit::parse('cepat/lambat'));
        $this->assertNull(MikrotikRateLimit::parse('0/8M'));
    }
}
