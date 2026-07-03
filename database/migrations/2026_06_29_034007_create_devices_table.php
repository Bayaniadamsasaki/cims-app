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
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('hostname')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('mac_address')->nullable();
            
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_category_id')->nullable()->constrained()->nullOnDelete();
            
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('firmware')->nullable();
            $table->date('purchase_date')->nullable();
            $table->string('warranty')->nullable(); // e.g. "3 Years" or date
            
            $table->foreignId('building_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('floor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rack_id')->nullable()->constrained()->nullOnDelete();
            
            $table->string('status')->default('active'); // e.g. active, maintenance, inactive
            $table->text('notes')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
