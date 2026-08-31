<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hasil speedtest sekarang bisa datang dari dua tempat yang artinya berbeda:
 * dari server aplikasi ke Cloudflare (SpeedtestService), atau dari container di
 * router itu sendiri (MikrotikContainerSpeedtestService). Tanpa penanda sumber,
 * keduanya bercampur dalam satu `SpeedtestResult::latest()` dan angka uplink
 * kampus jadi tidak bisa dibedakan dari angka jalur server — kartu di halaman
 * monitoring akan menampilkan salah satunya sambil mengaku sebagai yang lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('speedtest_results', function (Blueprint $table) {
            // Baris lama semuanya diukur dari server, jadi 'server' adalah
            // default yang benar secara historis — bukan sekadar nilai isian.
            $table->string('source', 32)->default('server')->after('id');
            $table->string('router_host')->nullable()->after('isp');
            $table->string('server_name')->nullable()->after('router_host');
            $table->text('raw_output')->nullable()->after('server_name');
        });

        // Keluaran speedtest tidak selalu memuat baris latensi. Menyimpan 0 ms
        // untuk hasil yang latensinya tidak dilaporkan berarti mengarang angka
        // yang nanti dibaca sebagai "latensi sempurna".
        Schema::table('speedtest_results', function (Blueprint $table) {
            $table->integer('ping_ms')->nullable()->change();
        });
    }

    public function down(): void
    {
        // ping_ms sengaja tidak dikembalikan ke NOT NULL: kalau sudah ada baris
        // dengan ping null, rollback-nya akan gagal di tengah jalan.
        Schema::table('speedtest_results', function (Blueprint $table) {
            $table->dropColumn(['source', 'router_host', 'server_name', 'raw_output']);
        });
    }
};
