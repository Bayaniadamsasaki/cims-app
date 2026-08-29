<?php

namespace App\Services;

use App\Models\SpeedtestResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Uji kecepatan gateway internet dengan pengukuran nyata ke Cloudflare.
 *
 * Kalau pengukuran gagal (internet mati, proxy memblokir, timeout), tidak ada
 * baris hasil yang disimpan dan penyebabnya dilempar sebagai exception —
 * angka pengganti tidak pernah dikarang.
 */
class SpeedtestService
{
    /**
     * Jalankan rangkaian uji kecepatan lengkap.
     *
     * @throws RuntimeException bila pengukuran nyata tidak bisa diselesaikan
     */
    public function runTest(): SpeedtestResult
    {
        $gateway = $this->probeGateway();

        return SpeedtestResult::create([
            'download_speed_mbps' => $this->measureDownload(),
            'upload_speed_mbps' => $this->measureUpload(),
            'ping_ms' => $gateway['latency'],
            // Nama ISP diambil dari organisasi AS yang dilaporkan Cloudflare.
            // Kalau tidak tersedia, dibiarkan null — bukan diisi nama acak.
            'isp' => $gateway['isp'],
        ]);
    }

    /**
     * Ukur latensi ke gateway internet dan baca identitas ISP yang sebenarnya.
     *
     * @return array{latency:int,isp:?string}
     *
     * @throws RuntimeException
     */
    protected function probeGateway(): array
    {
        $startTime = microtime(true);

        try {
            $response = Http::timeout(5)->get('https://speed.cloudflare.com/meta');

            if ($response->successful()) {
                return [
                    'latency' => (int) round((microtime(true) - $startTime) * 1000),
                    'isp' => $response->json('asOrganization') ?: null,
                ];
            }

            $reason = "HTTP {$response->status()}";
        } catch (\Throwable $e) {
            $reason = $e->getMessage();
        }

        Log::warning("Speedtest: probe latensi ke gateway internet gagal: {$reason}");

        throw new RuntimeException("Gagal menghubungi gateway internet untuk uji kecepatan: {$reason}");
    }

    /**
     * Ukur throughput download nyata (Mbps).
     *
     * @throws RuntimeException
     */
    protected function measureDownload(): float
    {
        $url = 'https://speed.cloudflare.com/__down?bytes=5000000'; // 5MB payload
        $startTime = microtime(true);

        try {
            $response = Http::timeout(15)->get($url);

            if ($response->successful()) {
                $duration = microtime(true) - $startTime;
                $bytes = strlen($response->body());

                if ($duration > 0 && $bytes > 0) {
                    return round((($bytes * 8) / 1000000) / $duration, 2);
                }

                $reason = 'respons kosong atau durasi tidak terukur';
            } else {
                $reason = "HTTP {$response->status()}";
            }
        } catch (\Throwable $e) {
            $reason = $e->getMessage();
        }

        Log::warning("Speedtest: pengukuran download gagal: {$reason}");

        throw new RuntimeException("Pengukuran download gagal: {$reason}");
    }

    /**
     * Ukur throughput upload nyata (Mbps).
     *
     * @throws RuntimeException
     */
    protected function measureUpload(): float
    {
        $url = 'https://speed.cloudflare.com/__up';
        $payload = str_repeat('a', 1500000); // 1.5MB of data
        $startTime = microtime(true);

        try {
            $response = Http::timeout(15)
                ->withBody($payload, 'application/octet-stream')
                ->post($url);

            if ($response->successful()) {
                $duration = microtime(true) - $startTime;

                if ($duration > 0) {
                    return round(((strlen($payload) * 8) / 1000000) / $duration, 2);
                }

                $reason = 'durasi tidak terukur';
            } else {
                $reason = "HTTP {$response->status()}";
            }
        } catch (\Throwable $e) {
            $reason = $e->getMessage();
        }

        Log::warning("Speedtest: pengukuran upload gagal: {$reason}");

        throw new RuntimeException("Pengukuran upload gagal: {$reason}");
    }
}
