<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('speedtest_testers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Default penguji sesuai spesifikasi fitur; tambahan dikelola dari UI.
        DB::table('speedtest_testers')->insert([
            ['name' => 'Rama', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Adam', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('speedtest_testers');
    }
};
