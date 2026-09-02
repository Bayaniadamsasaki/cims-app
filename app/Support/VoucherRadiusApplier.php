<?php

namespace App\Support;

use App\Models\HotspotVoucher;
use App\Services\RadiusService;
use Illuminate\Support\Str;

/**
 * Menerapkan voucher ke RADIUS lalu mencatat hasilnya di kolom status CIMS.
 *
 * Dua database, dua tanggung jawab. RadiusService hanya tahu isi RADIUS dan
 * melaporkan id mana yang sampai; yang tahu arti 'synced' hanyalah CIMS. Kelas
 * ini yang menjembatani keduanya, dan sengaja satu tempat karena tiga pemanggil
 * membutuhkannya: tombol Terapkan di halaman voucher, `radius:reconcile`, dan
 * sinkronisasi PMB.
 *
 * Statusnya ditulis dua UPDATE bulk, bukan 892 save() satu-satu — dan aturan yang
 * paling mudah terlupa kalau logika ini disalin: voucher yang diblokir TIDAK boleh
 * ikut berubah status.
 */
final class VoucherRadiusApplier
{
    /** Jumlah id per UPDATE. Cukup kecil untuk batas placeholder MySQL. */
    private const STATUS_CHUNK = 1000;

    public function __construct(protected RadiusService $radius)
    {
    }

    /**
     * @param  iterable<int,HotspotVoucher>  $vouchers
     * @return array{ok:int,failed:int,error:?string}
     */
    public function apply(iterable $vouchers): array
    {
        $result = $this->radius->upsertMany($vouchers);
        $now = now();
        $error = $result['error'] === null ? null : Str::limit((string) $result['error'], 500);

        foreach (array_chunk($result['ok_ids'], self::STATUS_CHUNK) as $batch) {
            $this->stamp($batch, HotspotVoucher::STATUS_SYNCED, null, $now);
        }

        foreach (array_chunk($result['failed_ids'], self::STATUS_CHUNK) as $batch) {
            $this->stamp($batch, HotspotVoucher::STATUS_FAILED, $error, null);
        }

        return ['ok' => $result['ok'], 'failed' => $result['failed'], 'error' => $result['error']];
    }

    /**
     * Tandai hasil penerapan untuk sekumpulan id.
     *
     * Voucher yang diblokir tidak ikut berubah status. Yang baru saja ditulis ke
     * RADIUS untuknya justru penolakannya sendiri ('Auth-Type := Reject'), dan
     * menaikkan statusnya ke synced/failed akan membuat penerapan berikutnya
     * mencabut blokir itu tanpa ada yang memintanya.
     *
     * @param  array<int,int>  $ids
     */
    private function stamp(array $ids, string $status, ?string $error, ?\DateTimeInterface $syncedAt): void
    {
        if ($ids === []) {
            return;
        }

        // last_error selalu ditulis — null berarti "pesan gagal yang lama
        // dibersihkan". synced_at hanya saat berhasil: kegagalan tidak boleh ikut
        // menghapus catatan kapan voucher ini terakhir benar-benar ada di RADIUS.
        $columns = ['last_error' => $error];

        if ($syncedAt !== null) {
            $columns['synced_at'] = $syncedAt;
        }

        HotspotVoucher::whereIn('id', $ids)
            ->where('status', '!=', HotspotVoucher::STATUS_DISABLED)
            ->update($columns + ['status' => $status]);

        HotspotVoucher::whereIn('id', $ids)
            ->where('status', HotspotVoucher::STATUS_DISABLED)
            ->update($columns);
    }
}
