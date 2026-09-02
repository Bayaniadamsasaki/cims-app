<?php

namespace App\Support;

use App\Models\HotspotVoucher;
use Illuminate\Support\Facades\DB;

/**
 * Menyatukan baris voucher yang NIM-nya sama.
 *
 * Selama voucher masih dikunci per (NIM, router), satu mahasiswa punya satu baris
 * untuk setiap alamat router yang pernah dipakai — dan alamat router hotspot
 * kampus memang bergeser sendiri karena ia DHCP client (lihat
 * {@see \App\Console\Commands\HotspotSyncRouterCommand}). Hasilnya baris kembar
 * dengan password yang bisa berbeda-beda.
 *
 * RADIUS tidak mengenal konsep itu: username hanya ada satu. Jadi sebelum kunci
 * unik dipindah ke NIM, kembarannya harus diselesaikan lebih dulu.
 *
 * Aturannya satu dan sengaja tidak pintar: yang terbaru menang (updated_at
 * terbesar, seri dipatahkan id terbesar). Baris terbaru adalah hasil sinkronisasi
 * PMB paling akhir, jadi ia yang paling dekat dengan kebenaran di SISKA.
 */
final class VoucherDeduplicator
{
    /** Jumlah contoh yang dibawa dalam laporan. */
    private const SAMPLES = 15;

    /**
     * Hitung dan (bila $apply) hapus baris kembar.
     *
     * @return array{total:int,unique:int,duplicate_nims:int,deleted:int,
     *               password_conflicts:int,samples:array<int,array<int,string>>,applied:bool}
     */
    public function run(bool $apply = false): array
    {
        $duplicates = HotspotVoucher::query()
            ->select('nim')
            ->groupBy('nim')
            ->havingRaw('count(*) > 1')
            ->pluck('nim');

        $report = [
            'total' => HotspotVoucher::count(),
            'unique' => (int) HotspotVoucher::query()->distinct()->count('nim'),
            'duplicate_nims' => $duplicates->count(),
            'deleted' => 0,
            'password_conflicts' => 0,
            'samples' => [],
            'applied' => $apply,
        ];

        foreach ($duplicates->chunk(500) as $chunk) {
            $losers = [];

            $rows = HotspotVoucher::query()
                ->whereIn('nim', $chunk->all())
                ->orderBy('nim')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get(['id', 'nim', 'password', 'router_host', 'status', 'updated_at']);

            foreach ($rows->groupBy('nim') as $nim => $group) {
                $keeper = $group->first();
                $dropped = $group->slice(1);

                $differs = $dropped->contains(fn ($row) => $row->password !== $keeper->password);

                if ($differs) {
                    $report['password_conflicts']++;
                }

                if (count($report['samples']) < self::SAMPLES) {
                    $report['samples'][] = [
                        (string) $nim,
                        (string) ($keeper->router_host ?? '-'),
                        $dropped->pluck('router_host')->map(fn ($h) => (string) ($h ?? '-'))->implode(', '),
                        $differs ? 'password beda' : 'password sama',
                    ];
                }

                $losers = array_merge($losers, $dropped->pluck('id')->all());
                $report['deleted'] += $dropped->count();
            }

            if ($apply && $losers !== []) {
                foreach (array_chunk($losers, 500) as $batch) {
                    DB::table((new HotspotVoucher)->getTable())->whereIn('id', $batch)->delete();
                }
            }
        }

        return $report;
    }
}
