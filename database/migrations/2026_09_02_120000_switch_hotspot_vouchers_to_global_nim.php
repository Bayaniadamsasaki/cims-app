<?php

use App\Support\VoucherDeduplicator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Voucher berhenti menjadi milik satu router dan menjadi milik satu mahasiswa.
 *
 * Sejak tujuan push berpindah ke RADIUS, hanya ada satu tempat yang menjawab
 * Access-Request untuk seluruh router hotspot kampus — maka username juga hanya
 * boleh ada satu. Kunci unik (nim, router_host) berganti menjadi nim saja.
 *
 * PERHATIAN: baris kembar dihapus di sini dan migrate:rollback TIDAK bisa
 * mengembalikannya. Backup database dulu. Jalankan
 * `php artisan hotspot:vouchers-dedupe --dry-run` untuk melihat apa yang hilang.
 *
 * router_host dan mikrotik_id sengaja dipertahankan: keduanya jejak router yang
 * dulu dipakai, berguna untuk menelusuri asal baris, tapi tidak lagi menentukan
 * apa pun.
 */
return new class extends Migration
{
    public function up(): void
    {
        $report = app(VoucherDeduplicator::class)->run(apply: true);

        if (app()->runningInConsole() && $report['deleted'] > 0) {
            echo '  '.$report['deleted'].' baris voucher berkembar-NIM dihapus ('
                .$report['password_conflicts'].' di antaranya berpassword berbeda); '
                .'tersisa '.$report['unique'].' NIM unik.'.PHP_EOL;
        }

        Schema::table('hotspot_vouchers', function (Blueprint $table) {
            // Asal baris menentukan apakah sinkronisasi PMB boleh menonaktifkannya.
            // Baris lama sengaja dianggap 'manual' — provenance-nya tidak bisa
            // dipastikan, dan menebak salah berarti mematikan WiFi mahasiswa yang
            // masih aktif. Sinkronisasi PMB berikutnya akan menandai sendiri baris
            // yang benar-benar dilihatnya sebagai 'pmb'.
            $table->string('source', 16)->default('manual')->after('status');

            // Kenapa sebuah voucher ditolak: 'tidak ada di PMB', dinonaktifkan
            // operator, dan seterusnya. Ditampilkan di halaman voucher supaya
            // status disabled tidak perlu ditebak.
            $table->string('disabled_reason')->nullable()->after('source');

            $table->index('source');
        });

        Schema::table('hotspot_vouchers', function (Blueprint $table) {
            $table->dropUnique(['nim', 'router_host']);
            $table->unique('nim');
        });

        // 'synced' sekarang berarti "ada di RADIUS", dan RADIUS belum berisi
        // apa pun dari CIMS. Status lama peninggalan push ke MikroTik akan
        // menyesatkan halaman voucher, jadi dikembalikan ke pending.
        DB::table('hotspot_vouchers')
            ->where('status', 'synced')
            ->update(['status' => 'pending', 'synced_at' => null]);
    }

    public function down(): void
    {
        Schema::table('hotspot_vouchers', function (Blueprint $table) {
            $table->dropUnique(['nim']);
            $table->unique(['nim', 'router_host']);
        });

        Schema::table('hotspot_vouchers', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'disabled_reason']);
        });
    }
};
