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
        Schema::create('device_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained()->onDelete('cascade');
            
            $table->string('last_ping_status')->default('offline'); // online, offline
            $table->integer('last_ping_latency_ms')->nullable();
            $table->integer('last_packet_loss_percent')->nullable();
            
            $table->integer('last_cpu_usage_percent')->nullable();
            $table->integer('last_ram_usage_percent')->nullable();
            $table->integer('last_storage_usage_percent')->nullable();
            $table->integer('last_temperature_celsius')->nullable();
            $table->bigInteger('last_uptime_seconds')->nullable();
            
            $table->json('last_interface_status')->nullable(); // e.g. [{"name": "ether1", "status": "up"}]
            $table->bigInteger('last_bandwidth_rx_bps')->nullable();
            $table->bigInteger('last_bandwidth_tx_bps')->nullable();
            
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_metrics');
    }
};
