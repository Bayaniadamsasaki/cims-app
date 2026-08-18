<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('speedtest_reports', function (Blueprint $table) {
            $table->id();
            $table->dateTime('tested_at');
            $table->string('location');
            $table->string('ssid');
            $table->decimal('download_mbps', 10, 2);
            $table->decimal('upload_mbps', 10, 2);
            $table->decimal('ping_ms', 8, 2);
            $table->enum('status', ['lancar', 'sedang', 'lambat', 'terputus']);
            $table->enum('device_type', ['laptop', 'smartphone', 'pc', 'tablet', 'lainnya']);
            $table->foreignId('tester_id')->constrained('speedtest_testers')->cascadeOnUpdate()->restrictOnDelete();
            $table->enum('action', ['maintenance', 'selesai', 'monitoring_traffic']);
            $table->string('screenshot_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('tested_at');
            $table->index('status');
            $table->index('location');
            $table->index('ssid');
            $table->index('action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('speedtest_reports');
    }
};
