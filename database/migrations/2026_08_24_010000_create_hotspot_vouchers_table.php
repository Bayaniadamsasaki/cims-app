<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voucher hotspot mahasiswa: sumber kebenaran ada di database ini, lalu di-push
 * ke /ip/hotspot/user pada router MikroTik. Status menyimpan hasil push terakhir
 * supaya kegagalan sinkronisasi tidak hilang begitu request selesai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotspot_vouchers', function (Blueprint $table) {
            $table->id();

            // Identitas mahasiswa — NIM dipakai sebagai username hotspot.
            $table->string('nim', 64);
            $table->string('student_name')->nullable();
            $table->string('faculty')->nullable();
            $table->string('program')->nullable();

            // Kredensial hotspot.
            $table->string('password', 128);
            $table->string('profile', 64)->nullable();
            $table->string('server', 64)->nullable();

            // Router tujuan. Non-null supaya unique index (nim, router_host)
            // benar-benar mengikat di MySQL maupun SQLite.
            $table->string('router_host', 64);
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();

            // Hasil sinkronisasi ke router.
            $table->string('status', 16)->default('pending'); // pending|synced|failed|disabled
            $table->string('mikrotik_id', 32)->nullable();    // .id milik entri /ip/hotspot/user
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();

            // Metadata operasional.
            $table->string('limit_uptime', 32)->nullable();
            $table->date('valid_until')->nullable();
            $table->string('batch_label', 64)->nullable();
            $table->string('comment')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['nim', 'router_host']);
            $table->index('status');
            $table->index('batch_label');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotspot_vouchers');
    }
};
