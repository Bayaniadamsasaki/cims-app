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
        Schema::create('speedtest_results', function (Blueprint $table) {
            $table->id();
            $table->decimal('download_speed_mbps', 8, 2);
            $table->decimal('upload_speed_mbps', 8, 2);
            $table->integer('ping_ms');
            $table->string('isp')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('speedtest_results');
    }
};
