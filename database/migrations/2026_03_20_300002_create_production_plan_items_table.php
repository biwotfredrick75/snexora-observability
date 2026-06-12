<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_plan_id')->constrained('production_plans')->noActionOnDelete();
            $table->string('stock_id', 50);
            $table->string('description', 255)->nullable();
            $table->decimal('current_stock', 18, 6)->default(0);
            $table->decimal('planned_qty', 18, 6)->default(0);
            $table->decimal('actual_qty', 18, 6)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_plan_items');
    }
};
