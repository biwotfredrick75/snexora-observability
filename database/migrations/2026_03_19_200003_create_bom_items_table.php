<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bom_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bom_id');
            $table->string('component_code', 20);                      // items.stock_id
            $table->string('description', 200)->default('');
            $table->decimal('qty_required', 14, 4)->default(0);
            $table->string('unit', 30)->default('');
            $table->decimal('waste_pct', 6, 2)->default(0);           // scrap/waste %
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('bom_id')->references('id')->on('boms')->noActionOnDelete();
            $table->index(['bom_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_items');
    }
};
