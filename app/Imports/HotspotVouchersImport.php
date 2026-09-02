<?php

namespace App\Imports;

use App\Models\HotspotVoucher;
use App\Support\HotspotVoucherWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;

/**
 * Import daftar mahasiswa dari Excel/CSV menjadi voucher hotspot berstatus
 * "pending" (belum dikirim ke router).
 *
 * Format file sengaja dibuat toleran: baris header dicari di 15 baris pertama
 * dan kolom dikenali dari namanya, sehingga file dari BAAK/prodi tidak perlu
 * disusun ulang. Bila tidak ada header sama sekali, kolom A dianggap NIM dan
 * kolom B nama mahasiswa.
 */
class HotspotVouchersImport implements ToCollection
{
    /** Jumlah voucher baru yang dibuat. */
    public int $created = 0;

    /** Jumlah voucher lama yang diperbarui. */
    public int $updated = 0;

    /** Jumlah baris yang dilewati (NIM kosong / duplikat dalam file). */
    public int $skipped = 0;

    /** Contoh NIM duplikat dalam satu file, untuk ditampilkan ke pengguna. */
    public array $duplicates = [];

    /** NIM yang sudah diproses dalam file ini. */
    protected array $seen = [];

    /**
     * Sinonim nama kolom → kunci internal.
     */
    protected const COLUMN_ALIASES = [
        'nim'          => ['nim', 'npm', 'username', 'user', 'no induk', 'nomor induk', 'nim mahasiswa', 'id mahasiswa'],
        'student_name' => ['nama', 'name', 'nama mahasiswa', 'nama lengkap', 'student'],
        'password'     => ['password', 'pass', 'sandi', 'kata sandi'],
        'profile'      => ['profile', 'profil', 'user profile', 'paket'],
        'program'      => ['prodi', 'program studi', 'program', 'jurusan', 'departemen'],
        'faculty'      => ['fakultas', 'faculty', 'unit'],
        'comment'      => ['keterangan', 'comment', 'catatan', 'ket'],
        'valid_until'  => ['valid until', 'berlaku sampai', 'masa berlaku', 'expired', 'kadaluarsa'],
    ];

    /** Penulis baris voucher, dipakai bersama perintah sinkronisasi SISKA. */
    protected HotspotVoucherWriter $writer;

    public function __construct(
        protected string $routerHost,
        protected ?string $defaultProfile = null,
        protected ?string $defaultServer = null,
        protected ?string $batchLabel = null,
        protected ?int $userId = null,
        protected ?string $validUntil = null,
    ) {
        $this->writer = new HotspotVoucherWriter(
            routerHost: $routerHost,
            defaultProfile: $defaultProfile,
            defaultServer: $defaultServer,
            batchLabel: $batchLabel,
            userId: $userId,
            validUntil: $validUntil,

            // Baris dari Excel ditandai 'import' supaya sinkronisasi PMB tidak
            // pernah menonaktifkannya: daftar unggahan biasanya memuat dosen,
            // staf, dan tamu yang memang tidak ada di PMB.
            source: HotspotVoucher::SOURCE_IMPORT,
        );
    }

    public function collection(Collection $rows): void
    {
        $rows = $rows->values();
        [$headerIndex, $map] = $this->detectHeader($rows);

        foreach ($rows as $index => $row) {
            if ($headerIndex !== null && $index <= $headerIndex) {
                continue;
            }

            $values = collect($row)->values()->all();
            $nim = $this->cell($values, $map['nim'] ?? 0);

            if ($nim === null || !preg_match(HotspotVoucherWriter::NIM_PATTERN, $nim)) {
                $this->skipped++;
                continue;
            }

            // Tanpa header, kolom A bisa berisi teks apa pun (judul laporan, label
            // "Total", dsb). NIM selalu memuat angka, jadi itu dipakai sebagai
            // penyaring agar file yang salah tidak menghasilkan voucher sampah.
            if ($headerIndex === null && !preg_match('/\d/', $nim)) {
                $this->skipped++;
                continue;
            }

            if (isset($this->seen[$nim])) {
                $this->skipped++;
                if (count($this->duplicates) < 10) {
                    $this->duplicates[] = $nim;
                }
                continue;
            }

            $this->seen[$nim] = true;

            $password = $this->cell($values, $map['password'] ?? null);
            $validUntil = $this->parseDate($this->cell($values, $map['valid_until'] ?? null)) ?? $this->validUntil;

            $voucher = $this->writer->upsert($nim, [
                'student_name' => $this->cell($values, $map['student_name'] ?? ($headerIndex === null ? 1 : null)),
                'program' => $this->cell($values, $map['program'] ?? null),
                'faculty' => $this->cell($values, $map['faculty'] ?? null),
                // Password = NIM bila file tidak menyediakan kolom password.
                'password' => $password ?? $nim,
                'profile' => $this->cell($values, $map['profile'] ?? null),
                'comment' => $this->cell($values, $map['comment'] ?? null),
                'valid_until' => $validUntil,
            ]);

            $voucher->wasRecentlyCreated ? $this->created++ : $this->updated++;
        }
    }

    /**
     * Cari baris header dan petakan indeks kolomnya.
     *
     * @return array{0: int|null, 1: array<string,int>}
     */
    protected function detectHeader(Collection $rows): array
    {
        foreach ($rows->take(15) as $index => $row) {
            $values = collect($row)->values()->all();
            $map = [];

            foreach ($values as $position => $value) {
                $label = Str::squish(Str::lower((string) $value));

                if ($label === '') {
                    continue;
                }

                foreach (self::COLUMN_ALIASES as $key => $aliases) {
                    if (isset($map[$key])) {
                        continue;
                    }

                    if (in_array($label, $aliases, true)) {
                        $map[$key] = $position;
                    }
                }
            }

            // Baris dianggap header hanya bila kolom NIM/username dikenali.
            if (isset($map['nim'])) {
                return [$index, $map];
            }
        }

        return [null, []];
    }

    /**
     * Ambil nilai sel sebagai string bersih, atau null bila kosong.
     */
    protected function cell(array $values, ?int $position): ?string
    {
        if ($position === null || !array_key_exists($position, $values)) {
            return null;
        }

        $value = $values[$position];

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        // NIM panjang sering terbaca sebagai float oleh PhpSpreadsheet.
        if (is_float($value) && floor($value) === $value) {
            $value = sprintf('%.0f', $value);
        }

        $value = Str::squish((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Terima tanggal berformat Excel serial, d/m/Y, atau Y-m-d.
     */
    protected function parseDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (ctype_digit($value) && (int) $value > 20000 && (int) $value < 90000) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((int) $value)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y', 'j/n/Y'] as $format) {
            $date = \DateTime::createFromFormat($format, $value);

            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
}
