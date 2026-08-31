<?php

namespace Tests\Fixtures;

use App\Services\MikrotikContainerSpeedtestService;

/**
 * Versi MikrotikContainerSpeedtestService tanpa RouterOS.
 *
 * Tiga titik sentuh ke router — daftar container, penanda batas log, dan baris
 * log baru — digantikan nilai yang ditentukan test. Yang diuji dengan ini adalah
 * logika keputusannya (kapan menolak start, kapan sebuah putaran dinyatakan
 * selesai, kapan dinyatakan gagal), bukan library RouterOS-nya.
 */
class FakeContainerSpeedtestService extends MikrotikContainerSpeedtestService
{
    /** @var array<string,mixed>|null */
    public ?array $fakeContainer = null;

    /**
     * Urutan jawaban findContainer(), dipakai untuk skenario yang statusnya
     * berubah di tengah jalan — restart() menghentikan container lalu menunggu
     * statusnya berubah menjadi stopped, dan itu mustahil diuji kalau setiap
     * pemanggilan menjawab hal yang sama. Kalau kosong, $fakeContainer dipakai.
     *
     * @var array<int,array<string,mixed>|null>
     */
    public array $fakeContainerQueue = [];

    /** @var array<int,string> */
    public array $fakeLogLines = [];

    public ?\Throwable $findError = null;

    public ?string $fakeBaseline = '*100';

    /** Berapa kali findContainer dipanggil — dipakai memastikan poll() tidak
     *  menghubungi router lagi setelah putaran selesai. */
    public int $findCalls = 0;

    /** Berapa detik total yang akan ditunggu kalau ini bukan test. */
    public int $waitedSeconds = 0;

    public function findContainer(?string $host = null): ?array
    {
        $this->findCalls++;

        if ($this->findError !== null) {
            throw $this->findError;
        }

        if ($this->fakeContainerQueue !== []) {
            return array_shift($this->fakeContainerQueue);
        }

        return $this->fakeContainer;
    }

    /** Tidak benar-benar tidur: test tidak boleh ikut menunggu router. */
    protected function wait(int $seconds): void
    {
        $this->waitedSeconds += $seconds;
    }

    protected function logBaseline(?string $host): ?string
    {
        return $this->fakeBaseline;
    }

    protected function newLogLines(?string $host, ?string $baseline): array
    {
        return $this->fakeLogLines;
    }

    /**
     * Bentuk satu baris /container/print seperti yang dikembalikan RouterOS,
     * lengkap dengan boolean sebagai string 'true'/'false'.
     *
     * @return array<string,mixed>
     */
    public function makeContainer(string $status = 'stopped', bool $logging = true): array
    {
        $method = new \ReflectionMethod(MikrotikContainerSpeedtestService::class, 'mapContainer');

        return $method->invoke($this, [
            '.id' => '*1',
            'name' => 'speedtest-cli:latest',
            'tag' => 'registry-1.docker.io/tangentsoft/speedtest-cli:latest',
            'root-dir' => 'speedtest-cli',
            'interface' => 'veth1',
            'envlist' => 'speedtest_envs',
            'status' => $status,
            'logging' => $logging ? 'true' : 'false',
        ]);
    }
}
