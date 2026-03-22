<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printing_profile_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('printing_profiles')->cascadeOnDelete();
            $table->string('report_id', 20);
            $table->string('printer', 50)->default('Default');
            $table->timestamps();

            $table->unique(['profile_id', 'report_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printing_profile_items');
    }
};
