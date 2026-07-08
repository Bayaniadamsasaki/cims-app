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
        Schema::create('device_interfaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('interface_name'); // e.g. ether1, GigabitEthernet0/0
            $table->string('ip_address')->nullable();
            $table->string('subnet')->nullable(); // e.g. 255.255.255.0 or /24
            $table->string('gateway')->nullable();
            $table->string('mac_address')->nullable();
            $table->string('interface_type')->nullable(); // ethernet, wireless, vlan, loopback, etc.
            $table->string('interface_status')->default('down'); // up, down, disabled
            $table->string('speed')->nullable(); // e.g. 1Gbps, 100Mbps
            $table->integer('mtu')->nullable(); // e.g. 1500
            $table->text('description')->nullable();
            $table->timestamps();

            // Indexes per TASK_001 spec
            $table->index('ip_address');
            $table->index('mac_address');
            $table->index('interface_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_interfaces');
    }
};
