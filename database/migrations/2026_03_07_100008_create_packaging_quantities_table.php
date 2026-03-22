<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packaging_quantities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('packaging_type_id')->nullable();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->string('condition', 50)->nullable();
            $table->string('location_type', 50)->nullable();
            $table->string('location_code', 20)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('inactive')->default(false);
            $table->timestamps();

            $table->foreign('packaging_type_id')->references('id')->on('packaging_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packaging_quantities');
    }
};
