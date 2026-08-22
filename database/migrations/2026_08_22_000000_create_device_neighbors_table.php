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
        Schema::create('device_neighbors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('interface_name'); // local interface the neighbor was seen on
            $table->string('mac_address')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('identity')->nullable(); // neighbor hostname / RouterOS identity
            $table->string('platform')->nullable(); // MikroTik, Cisco, ...
            $table->string('board')->nullable(); // e.g. RB450Gx4
            $table->string('version')->nullable(); // e.g. 7.23.2
            $table->timestamps();

            $table->index(['device_id', 'interface_name']);
            $table->index('mac_address');
            $table->index('ip_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_neighbors');
    }
};
