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
        Schema::create('milk_purchase_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('farmer_id')->nullable();
            $table->decimal('quantity', 12, 3)->default(0);
            $table->timestamps();

            $table->foreign('purchase_id')->references('id')->on('milk_purchases')->noActionOnDelete();
            $table->foreign('farmer_id')->references('id')->on('farmers')->noActionOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milk_purchase_items');
    }
};
