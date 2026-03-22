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
        Schema::create('dimensions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique(); // e.g. 001/2026
            $table->string('name', 150);
            $table->unsignedTinyInteger('type')->default(1); // 1 or 2
            $table->date('start_date')->nullable();
            $table->date('date_required_by')->nullable();
            $table->json('tags')->nullable();
            $table->text('memo')->nullable();
            $table->boolean('inactive')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dimensions');
    }
};
