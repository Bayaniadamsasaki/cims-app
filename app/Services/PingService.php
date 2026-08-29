<?php

namespace App\Services;

/**
 * ICMP echo nyata ke alamat perangkat.
 *
 * Kelas ini sengaja dipisah dari MonitoringService supaya jalur ICMP punya satu
 * pintu masuk yang bisa diuji. Tidak ada mode simulasi di sini: kalau ICMP tidak
 * bisa dijalankan, itu dilaporkan sebagai error monitoring — bukan diganti angka
 * buatan.
 */
class PingService
{
    /**
     * Kirim satu ICMP echo ke $ip.
     *
     * @return array{online:bool,latency:?int,packet_loss:?int,error:?string}
     */
    public function check(string $ip, int $timeoutMs = 1000): array
    {
        if (! function_exists('exec') || ! is_callable('exec')) {
            // Tanpa exec() kita tidak tahu apa-apa soal perangkat. Melaporkannya
            // "offline" akan membuat seluruh inventaris tampak mati padahal yang
            // rusak adalah servernya sendiri.
            return [
                'online' => false,
                'latency' => null,
                'packet_loss' => null,
                'error' => 'ICMP tidak dapat dijalankan: fungsi exec() dinonaktifkan pada server ini.',
            ];
        }

        $command = PHP_OS_FAMILY === 'Windows'
            ? 'ping -n 1 -w ' . $timeoutMs . ' ' . escapeshellarg($ip)
            : 'ping -c 1 -W ' . max(1, (int) ceil($timeoutMs / 1000)) . ' ' . escapeshellarg($ip);

        $output = [];
        $exitCode = 1;
        @exec($command, $output, $exitCode);

        $latency = $this->parseLatency($output);

        // Ping di Windows bisa keluar dengan status 0 saat router perantara
        // menjawab "Destination host unreachable" — balasan echo yang sah selalu
        // menyertakan TTL (atau setidaknya waktu tempuh), jadi itu dipakai
        // sebagai bukti perangkatnya benar-benar menjawab.
        $replied = $exitCode === 0
            && ($latency !== null || (bool) preg_grep('/ttl[=\s:]/i', $output));

        if (! $replied) {
            return [
                'online' => false,
                'latency' => null,
                'packet_loss' => 100,
                'error' => null,
            ];
        }

        return [
            'online' => true,
            'latency' => $latency,
            'packet_loss' => 0,
            'error' => null,
        ];
    }

    /**
     * Waktu tempuh dari keluaran ping, dalam milidetik. Null kalau formatnya
     * tidak dikenali — angka pengganti tidak pernah dikarang.
     *
     * @param  array<int,string>  $output
     */
    protected function parseLatency(array $output): ?int
    {
        foreach ($output as $line) {
            if (preg_match('/(?:time|waktu)[=<]\s*([\d.,]+)\s*ms/i', $line, $matches)) {
                return (int) round((float) str_replace(',', '.', $matches[1]));
            }
        }

        return null;
    }
}
