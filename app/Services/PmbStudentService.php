<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Satu-satunya tempat aplikasi berbicara dengan API SISKA/PMB.
 *
 * Tugasnya berhenti pada "daftar mahasiswa yang rapi": halaman dirangkai
 * sampai habis, kode program studi diterjemahkan jadi nama, dan dari 38 kolom
 * yang dikirim API hanya empat yang diteruskan. Sisanya data pribadi (NIK,
 * alamat, nama orang tua, nomor telepon, foto, hash sandi SISKA) yang tidak
 * ada urusannya dengan voucher WiFi, jadi tidak pernah keluar dari kelas ini.
 */
class PmbStudentService
{
    /** Batas halaman, supaya meta yang keliru tidak membuat loop tak berujung. */
    protected const MAX_PAGES = 200;

    /**
     * Daftar mahasiswa dari SISKA, sudah unik per NIM.
     *
     * @param  array<string,string|int|null>  $filters  mis. ['program_studi_kode' => 18]
     * @param  callable(int,int,array):void|null  $onPage  dipanggil tiap halaman selesai
     * @return array<int,array{nim:string,student_name:?string,program:?string,birth_date:?string}>
     */
    public function students(array $filters = [], ?callable $onPage = null): array
    {
        $url = $this->url();
        $token = $this->token();
        $perPage = max(1, (int) config('services.pmb.per_page', 200));

        $students = [];
        $programs = [];
        $page = 1;

        do {
            $body = $this->get($url, $token, array_filter(
                ['page' => $page, 'per_page' => $perPage] + $filters,
                fn ($value) => $value !== null && $value !== '',
            ));

            // Daftar prodi dikirim ulang di setiap halaman; cukup diambil sekali.
            $programs = $programs ?: $this->programMap($body['list_program_studi'] ?? []);

            $rows = is_array($body['data'] ?? null) ? $body['data'] : [];

            foreach ($rows as $row) {
                $nim = trim((string) ($row['nim'] ?? ''));

                if ($nim === '') {
                    continue;
                }

                $students[$nim] = [
                    'nim' => $nim,
                    'student_name' => $this->text($row['nama_mahasiswa'] ?? null),
                    'program' => $programs[trim((string) ($row['program_studi_kode'] ?? ''))] ?? null,
                    'birth_date' => $this->text($row['tanggal_lahir'] ?? null),
                ];
            }

            if ($onPage) {
                $onPage($page, count($rows), is_array($body['meta'] ?? null) ? $body['meta'] : []);
            }

            $hasMore = (bool) ($body['meta']['has_more_pages'] ?? false);
            $page++;
        } while ($hasMore && $rows !== [] && $page <= self::MAX_PAGES);

        return array_values($students);
    }

    /** Alamat dan token sudah terisi, jadi tombol/perintah sinkronisasi ada gunanya. */
    public function configured(): bool
    {
        return trim((string) config('services.pmb.url')) !== ''
            && trim((string) config('services.pmb.token')) !== '';
    }

    /**
     * Satu permintaan GET, dengan pesan gagal yang menyebut penyebabnya.
     *
     * @param  array<string,string|int>  $query
     * @return array<string,mixed>
     */
    protected function get(string $url, string $token, array $query): array
    {
        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(max(5, (int) config('services.pmb.timeout', 30)))
                // Hanya gangguan sementara yang diulang. HTTP 401/404 tidak akan
                // membaik dengan dicoba lagi, jadi langsung dilaporkan.
                ->retry(max(1, (int) config('services.pmb.retries', 3)), 500, function ($exception) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if (! $exception instanceof RequestException) {
                        return false;
                    }

                    $status = $exception->response->status();

                    return $status === 429 || $status >= 500;
                }, throw: false)
                ->get($url, $query);
        } catch (ConnectionException $e) {
            throw new RuntimeException("API SISKA tidak bisa dihubungi di {$url}: {$e->getMessage()}");
        }

        if ($response->failed()) {
            throw new RuntimeException(
                "API SISKA menjawab HTTP {$response->status()} pada halaman {$query['page']}"
                . ($response->status() === 401 ? ' — periksa PMB_API_TOKEN di .env.' : '.')
            );
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('Jawaban API SISKA bukan JSON yang bisa dibaca.');
        }

        if (array_key_exists('success', $body) && ! $body['success']) {
            throw new RuntimeException('API SISKA menolak permintaan: '
                . ($body['message'] ?? 'tanpa keterangan'));
        }

        return $body;
    }

    /**
     * Kode program studi → namanya. API mengirim kode pada baris mahasiswa dan
     * namanya hanya di list_program_studi, jadi keduanya disatukan di sini.
     *
     * @param  array<int,array<string,mixed>>  $list
     * @return array<string,string>
     */
    protected function programMap(array $list): array
    {
        $map = [];

        foreach ($list as $item) {
            if (! is_array($item)) {
                continue;
            }

            $code = trim((string) ($item['id'] ?? $item['program_studi_kode'] ?? ''));
            $name = $this->text($item['nama_program_studi'] ?? $item['nama'] ?? null);

            if ($code !== '' && $name !== null) {
                $map[$code] = $name;
            }
        }

        return $map;
    }

    /** Nilai teks yang sudah dirapikan, atau null bila kosong. */
    protected function text(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function url(): string
    {
        $url = trim((string) config('services.pmb.url'));

        if ($url === '') {
            throw new RuntimeException('API_PMB belum diisi di .env — alamat API SISKA tidak diketahui.');
        }

        return $url;
    }

    protected function token(): string
    {
        $token = trim((string) config('services.pmb.token'));

        if ($token === '') {
            throw new RuntimeException('PMB_API_TOKEN belum diisi di .env — API SISKA butuh bearer token.');
        }

        return $token;
    }
}
