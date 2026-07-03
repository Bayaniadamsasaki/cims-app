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
        Schema::create('monitoring_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->onDelete('cascade');
            
            $table->string('status')->default('offline'); // online, offline
            $table->integer('ping_latency_ms')->nullable();
            $table->integer('packet_loss_percent')->nullable();
            
            $table->integer('cpu_usage_percent')->nullable();
            $table->integer('ram_usage_percent')->nullable();
            $table->integer('storage_usage_percent')->nullable();
            $table->integer('temperature_celsius')->nullable();
            $table->bigInteger('uptime_seconds')->nullable();
            
            $table->bigInteger('bandwidth_rx_bps')->nullable();
            $table->bigInteger('bandwidth_tx_bps')->nullable();
            
            $table->timestamp('checked_at');
            $table->timestamps();
            
            // Indexing for faster reporting queries
            $table->index(['device_id', 'checked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monitoring_logs');
    }
};
