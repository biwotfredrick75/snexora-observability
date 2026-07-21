<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milk_quality_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('min_butterfat_percent', 5, 2)->default(3.00);
            $table->decimal('min_density', 6, 4)->default(1.0260);
            $table->decimal('max_density', 6, 4)->default(1.0320);
            $table->boolean('reject_on_alcohol_positive')->default(true);
            $table->boolean('reject_on_adulteration_positive')->default(true);
            $table->boolean('reject_on_abnormal_smell')->default(true);
            $table->timestamps();
        });

        // Singleton row, id = 1 — same pattern as company_preferences.
        DB::table('milk_quality_settings')->insert([
            'id'         => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('milk_quality_settings');
    }
};
