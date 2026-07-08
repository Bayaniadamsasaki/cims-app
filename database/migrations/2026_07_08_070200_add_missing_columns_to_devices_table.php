<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds: operating_system_id, device_type_id, username, password
     * Adds explicit indexes per TASK_001 spec.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // New FK columns
            $table->foreignId('operating_system_id')
                ->nullable()
                ->after('device_category_id')
                ->constrained('operating_systems')
                ->nullOnDelete();

            $table->foreignId('device_type_id')
                ->nullable()
                ->after('operating_system_id')
                ->constrained('device_types')
                ->nullOnDelete();

            // Credential fields (per spec)
            $table->string('username')->nullable()->after('warranty');
            $table->text('password_encrypted')->nullable()->after('username');

            // Explicit indexes per TASK_001 spec
            $table->index('hostname');
            $table->index('ip_address');
            $table->index('mac_address');
            $table->index('serial_number');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex(['hostname']);
            $table->dropIndex(['ip_address']);
            $table->dropIndex(['mac_address']);
            $table->dropIndex(['serial_number']);
            $table->dropIndex(['status']);

            $table->dropConstrainedForeignId('operating_system_id');
            $table->dropConstrainedForeignId('device_type_id');

            $table->dropColumn(['username', 'password_encrypted']);
        });
    }
};
