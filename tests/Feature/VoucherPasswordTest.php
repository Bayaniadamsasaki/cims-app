<?php

namespace Tests\Feature;

use App\Support\VoucherPassword;
use Tests\TestCase;

/**
 * Password voucher dijanjikan ke mahasiswa sebagai tanggal lahir dengan urutan
 * tanggal-bulan-tahun, sementara SISKA menyimpannya terbalik dan untuk sebagian
 * mahasiswa kolomnya kosong. Kelas ini satu-satunya tempat aturannya ditulis,
 * jadi di sinilah semua bentuk masukan yang mungkin diuji.
 */
class VoucherPasswordTest extends TestCase
{
    private const NIM = '2101001';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.hotspot.password_format' => 'dmY']);
    }

    public function test_it_turns_the_iso_birth_date_around_into_day_month_year(): void
    {
        $password = VoucherPassword::forStudent(self::NIM, '1988-05-30');

        $this->assertSame('30051988', $password->value);
        $this->assertTrue($password->usesBirthDate());
        $this->assertSame(VoucherPassword::SOURCE_BIRTH_DATE, $password->source);
    }

    public function test_the_format_follows_env_so_six_digit_passwords_need_no_code_change(): void
    {
        config(['services.hotspot.password_format' => 'dmy']);

        $this->assertSame('300588', VoucherPassword::forStudent(self::NIM, '1988-05-30')->value);
    }

    /**
     * Tanggal dibaca sebagai tanggal, bukan potongan string — bentuk masukan yang
     * berbeda untuk hari yang sama harus menghasilkan password yang sama.
     */
    public function test_the_same_day_written_differently_gives_the_same_password(): void
    {
        $this->assertSame(
            VoucherPassword::forStudent(self::NIM, '1988-05-30')->value,
            VoucherPassword::forStudent(self::NIM, '1988-05-30 00:00:00')->value,
        );
    }

    /**
     * Ini permintaan operator: mahasiswa tanpa tanggal lahir tetap dapat voucher
     * yang bisa dipakai, bukan baris tertahan.
     */
    public function test_a_missing_birth_date_falls_back_to_the_nim(): void
    {
        foreach ([null, '', '   ', '0000-00-00', 'tidak diketahui'] as $birthDate) {
            $password = VoucherPassword::forStudent(self::NIM, $birthDate);

            $this->assertSame(self::NIM, $password->value, 'Tanggal lahir ' . var_export($birthDate, true));
            $this->assertFalse($password->usesBirthDate());
            $this->assertSame(VoucherPassword::SOURCE_NIM, $password->source);
        }
    }

    /**
     * SISKA memuat beberapa baris bertahun berjalan — itu salah entri, dan
     * passwordnya tidak boleh ikut salah.
     */
    public function test_an_implausible_birth_year_falls_back_to_the_nim(): void
    {
        $thisYear = now()->format('Y') . '-01-01';

        $this->assertSame(self::NIM, VoucherPassword::forStudent(self::NIM, $thisYear)->value);
        $this->assertSame(self::NIM, VoucherPassword::forStudent(self::NIM, '1899-01-01')->value);
        $this->assertNull(VoucherPassword::formatBirthDate($thisYear));
    }
}
