<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('physical_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_a_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('interface_a_id')->nullable()->constrained('device_interfaces')->nullOnDelete();
            $table->foreignId('device_b_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->foreignId('interface_b_id')->nullable()->constrained('device_interfaces')->nullOnDelete();
            $table->string('relationship_type')->default('physical');
            $table->string('discovery_source');
            $table->string('verification_status')->default('verified');
            $table->string('dedupe_key')->unique();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['device_a_id', 'device_b_id']);
            $table->index(['interface_a_id', 'interface_b_id']);
            $table->index(['verification_status', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_links');
    }
};
