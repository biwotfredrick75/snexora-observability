<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_conversion_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('component_id');
            $table->decimal('quantity_produced', 18, 8)->default(1);
            $table->timestamps();

            $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
            $table->foreign('component_id')->references('id')->on('items');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_conversion_items');
    }
};
