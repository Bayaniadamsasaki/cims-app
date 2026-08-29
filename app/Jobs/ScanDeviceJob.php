<?php

namespace App\Jobs;

use App\Models\Device;
use App\Services\MonitoringService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pemindaian satu perangkat jaringan, satu job.
 *
 * Satu perangkat per job dipilih supaya kegagalan satu titik jaringan tidak
 * pernah menjatuhkan pemindaian perangkat lain: yang gagal hanya job miliknya
 * sendiri, sisanya tetap berjalan. Jumlah koneksi jaringan yang terbuka
 * bersamaan ditentukan oleh jumlah worker antrean, bukan oleh loop di dalam
 * aplikasi — jadi tidak ada ledakan koneksi paralel walau inventaris berisi
 * ratusan perangkat.
 *
 * Job ini sengaja tidak memuat logika pengukuran apa pun. Seluruh pembacaan
 * ICMP/RouterOS API/SNMP, penyimpanan metrik, riwayat, dan alert tetap milik
 * MonitoringService, termasuk aturannya: tidak ada nilai karangan, kegagalan
 * dicatat apa adanya.
 */
class ScanDeviceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Batas waktu satu pemindaian. Diambil dari config saat job dibuat dan
     * ikut tersimpan di payload, sehingga worker memakai batas yang sama
     * dengan yang berlaku waktu job dijadwalkan.
     */
    public int $timeout;

    /** Percobaan maksimum sebelum job dinyatakan gagal permanen. */
    public int $tries;

    public function __construct(public int $deviceId)
    {
        $this->onQueue(config('monitoring.queue'));
        $this->timeout = (int) config('monitoring.job_timeout');
        $this->tries = (int) config('monitoring.job_tries');
    }

    /**
     * Kunci anti-duplikat per perangkat: selama job untuk perangkat ini masih
     * mengantre atau sedang diproses, dispatch berikutnya diabaikan. Scheduler
     * per menit karena itu aman walau satu pemindaian melewati satu menit.
     */
    public function uniqueId(): string
    {
        return (string) $this->deviceId;
    }

    /** Umur maksimum kunci, agar worker yang mati tidak memblokir selamanya. */
    public function uniqueFor(): int
    {
        return (int) config('monitoring.unique_for');
    }

    /**
     * Jeda naik antar percobaan (detik) untuk gangguan sesaat. Kegagalan
     * jaringan yang nyata sudah dicatat MonitoringService pada percobaan
     * pertama; percobaan ulang di sini hanya untuk kesalahan tak terduga.
     *
     * @return array<int,int>
     */
    public function backoff(): array
    {
        return config('monitoring.job_backoff', [10, 30]);
    }

    public function handle(MonitoringService $monitoring): void
    {
        $device = Device::with(['vendor', 'operatingSystem', 'metrics'])->find($this->deviceId);

        if ($device === null) {
            // Perangkat dihapus dari inventaris setelah job dijadwalkan. Ini
            // bukan kegagalan monitoring, jadi tidak perlu percobaan ulang.
            Log::info("Pemindaian dilewati: perangkat #{$this->deviceId} sudah tidak ada di inventaris.");

            return;
        }

        $monitoring->scanDevice($device);
    }

    /**
     * Kegagalan tak terduga di luar jalur jaringan — kegagalan jaringan sendiri
     * sudah tersimpan sebagai status nyata oleh MonitoringService. Di sini
     * hanya dicatat; tidak ada metrik pengganti yang ditulis.
     */
    public function failed(?Throwable $e): void
    {
        Log::error(
            "Job pemindaian perangkat #{$this->deviceId} gagal permanen setelah {$this->tries} percobaan: "
            .($e?->getMessage() ?? 'penyebab tidak diketahui')
        );
    }
}
