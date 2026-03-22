<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('work_order_id');
            $table->string('component_code', 20);                      // items.stock_id
            $table->string('description', 200)->default('');
            $table->decimal('qty_required', 14, 4)->default(0);        // from BOM × planned qty ratio
            $table->decimal('qty_issued', 14, 4)->default(0);          // actual issued so far
            $table->string('unit', 30)->default('');
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('waste_pct', 6, 2)->default(0);
            $table->decimal('line_total', 14, 4)->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->index(['work_order_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_items');
    }
};
