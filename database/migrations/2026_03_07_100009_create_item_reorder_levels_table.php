<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_reorder_levels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('location_id');
            $table->decimal('reorder_level', 18, 6)->default(0);
            $table->timestamps();

            $table->foreign('item_id')->references('id')->on('items')->noActionOnDelete();
            $table->foreign('location_id')->references('id')->on('inventory_locations')->noActionOnDelete();
            $table->unique(['item_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_reorder_levels');
    }
};
