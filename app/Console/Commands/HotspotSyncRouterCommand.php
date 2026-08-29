<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\HotspotVoucher;
use App\Services\MikrotikService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Router hotspot kampus adalah DHCP client di subnet Mobile hotspot Windows,
 * jadi alamatnya bisa bergeser sendiri. Tiga tempat ikut basi bersamaan:
 * HOTSPOT_ROUTER_HOST di .env, IP baris perangkat di inventaris (sumber
 * kredensial API), dan router_host di baris voucher (penentu isi halaman).
 *
 * Perintah ini membuat .env tetap satu-satunya tempat yang diubah tangan:
 * setelah HOTSPOT_ROUTER_HOST diperbarui, dua sisanya menyusul otomatis.
 */
class HotspotSyncRouterCommand extends Command
{
    protected $signature = 'hotspot:sync-router
                            {--from= : Alamat router lama, kalau tidak bisa dideteksi otomatis}
                            {--device= : ID perangkat inventaris yang mau dipindah}
                            {--dry-run : Tampilkan rencananya tanpa menulis ke database}';

    protected $description = 'Ikutkan inventaris & baris voucher ke alamat HOTSPOT_ROUTER_HOST di .env';

    public function handle(MikrotikService $mikrotik): int
    {
        $target = trim((string) config('services.hotspot.router_host'));

        if ($target === '') {
            $this->error('HOTSPOT_ROUTER_HOST belum diisi di .env — tidak ada tujuan yang bisa disinkronkan.');

            return self::FAILURE;
        }

        $device = $this->resolveDevice();

        if ($device === false) {
            return self::FAILURE;
        }

        $from = $this->resolveOldHost($target, $device);

        if ($from === false) {
            return self::FAILURE;
        }

        // Perangkat lain sudah memakai alamat tujuan: jangan ditimpa, biarkan
        // operator yang memutuskan mana baris inventaris yang benar.
        $occupant = Device::where('ip_address', $target)
            ->when($device, fn ($q) => $q->whereKeyNot($device->getKey()))
            ->first();

        if ($occupant && $device) {
            $this->warn("Perangkat #{$occupant->id} ({$occupant->name}) sudah memakai {$target}; "
                . "IP perangkat #{$device->id} dibiarkan apa adanya.");
            $device = null;
        }

        $voucherQuery = $from !== null
            ? HotspotVoucher::where('router_host', $from)
            : HotspotVoucher::whereRaw('1 = 0');
        $voucherCount = $voucherQuery->count();

        $this->table(['Yang disinkronkan', 'Dari', 'Jadi'], [
            ['HOTSPOT_ROUTER_HOST (.env)', '-', $target],
            [
                $device ? "Inventaris #{$device->id} ({$device->name})" : 'Inventaris',
                $device ? $device->ip_address : 'tidak ada yang perlu dipindah',
                $device ? $target : '-',
            ],
            [
                'Baris voucher',
                $from ?? '-',
                $voucherCount > 0 ? "{$voucherCount} baris → {$target}" : 'tidak ada yang perlu dipindah',
            ],
        ]);

        if ($this->option('dry-run')) {
            $this->info('Dry run: database tidak disentuh.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($device, $target, $voucherQuery, $voucherCount) {
            if ($device && $device->ip_address !== $target) {
                $device->ip_address = $target;
                $device->save();
                $this->info("Inventaris #{$device->id} sekarang menunjuk {$target}.");
            }

            if ($voucherCount > 0) {
                // mikrotik_id tetap dipertahankan: routernya masih boks yang
                // sama, hanya alamatnya yang berpindah, jadi voucher yang sudah
                // synced tidak perlu dipush ulang.
                $voucherQuery->update(['router_host' => $target]);
                $this->info("{$voucherCount} baris voucher dipindah ke {$target}.");
            }
        });

        return $this->probe($mikrotik, $target);
    }

    /**
     * Perangkat inventaris yang mau dipindah, berdasarkan opsi eksplisit.
     *
     * @return Device|null|false null = biar dideteksi dari alamat lama, false = gagal
     */
    protected function resolveDevice(): Device|null|false
    {
        if ($id = $this->option('device')) {
            $device = Device::find($id);

            if (!$device) {
                $this->error("Perangkat inventaris #{$id} tidak ada.");

                return false;
            }

            return $device;
        }

        return null;
    }

    /**
     * Alamat lama router: dari opsi, dari perangkat yang disebut, atau ditebak
     * dari router_host yang dipakai baris voucher yang ada.
     *
     * @return string|null|false null = tidak ada yang perlu dipindah, false = gagal
     */
    protected function resolveOldHost(string $target, ?Device &$device): string|null|false
    {
        if ($from = $this->option('from')) {
            $device = $device ?: Device::where('ip_address', $from)->first();

            if (!$device) {
                $this->warn("Tidak ada perangkat inventaris ber-IP {$from}; hanya baris voucher yang dipindah.");
            }

            return $from;
        }

        if ($device && $device->ip_address !== $target) {
            return $device->ip_address;
        }

        $candidates = HotspotVoucher::whereNotNull('router_host')
            ->where('router_host', '!=', $target)
            ->distinct()
            ->pluck('router_host');

        if ($candidates->count() > 1) {
            // Jejak lease lama sering menumpuk di baris voucher. Yang benar-benar
            // router kita adalah alamat yang punya baris inventaris — di situlah
            // kredensial API-nya tersimpan; sisanya alamat yang sudah mati.
            $known = $candidates
                ->filter(fn ($host) => Device::where('ip_address', $host)->exists())
                ->values();

            if ($known->count() === 1) {
                $candidates = $known;
            }
        }

        if ($candidates->count() > 1) {
            $this->error('Beberapa alamat router lama terdeteksi di baris voucher: '
                . $candidates->implode(', ') . '. Sebutkan yang mana dengan --from=.');

            return false;
        }

        if ($candidates->count() === 1) {
            $from = (string) $candidates->first();
            $device = $device ?: Device::where('ip_address', $from)->first();

            if (!$device) {
                $this->warn("Tidak ada perangkat inventaris ber-IP {$from}; hanya baris voucher yang dipindah.");
            }

            return $from;
        }

        if (!$device && !Device::where('ip_address', $target)->exists()) {
            $this->error("Tidak ada perangkat inventaris ber-IP {$target} dan alamat lamanya tidak bisa "
                . 'ditebak dari baris voucher. Sebutkan dengan --from= atau --device=.');

            return false;
        }

        $this->info("Inventaris dan baris voucher sudah menunjuk {$target}.");

        return null;
    }

    /**
     * Buktikan aplikasi benar-benar bisa login ke router di alamat baru.
     */
    protected function probe(MikrotikService $mikrotik, string $target): int
    {
        $this->newLine();
        $this->info("Menguji koneksi RouterOS ke {$target}...");

        $result = $mikrotik->testConnection($target);

        if (!($result['success'] ?? false)) {
            $this->error("Router {$target} belum bisa dihubungi: " . ($result['error'] ?? 'unknown'));
            $this->line('Database sudah sinkron; yang belum beres tinggal koneksi ke routernya.');

            return self::FAILURE;
        }

        $this->info("Koneksi OK — {$result['identity']} ({$result['board']}, RouterOS {$result['version']}) "
            . 'via ' . ($mikrotik->credentialSourceFor($target) ?? 'kredensial default') . '.');

        return self::SUCCESS;
    }
}
