<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('wo_no', 30)->unique();
            $table->string('product_code', 20);                          // items.stock_id
            $table->string('product_description', 200)->default('');
            $table->unsignedBigInteger('bom_id')->nullable();
            $table->unsignedBigInteger('work_centre_id')->nullable();
            $table->decimal('planned_qty', 14, 4)->default(0);
            $table->decimal('actual_qty_produced', 14, 4)->default(0);
            $table->string('unit', 20)->default('');
            $table->date('start_date');
            $table->date('due_date');
            $table->date('completed_date')->nullable();
            // Status: draft → released → in_progress → completed → closed
            $table->string('status', 30)->default('draft');
            $table->string('location_code', 20)->default('');           // production location
            $table->string('output_location_code', 20)->default('');    // finished goods location
            // Cost accumulation
            $table->decimal('total_material_cost', 14, 4)->default(0);
            $table->decimal('total_labour_cost', 14, 4)->default(0);
            $table->decimal('total_overhead_cost', 14, 4)->default(0);
            $table->decimal('total_scrap_cost', 14, 4)->default(0);
            $table->decimal('total_cost', 14, 4)->default(0);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->text('notes')->nullable();
            $table->string('created_by', 50)->default('');
            $table->string('released_by', 50)->nullable();
            $table->string('completed_by', 50)->nullable();
            $table->timestamps();

            $table->foreign('bom_id')->references('id')->on('boms')->noActionOnDelete();
            $table->foreign('work_centre_id')->references('id')->on('work_centres')->noActionOnDelete();
            $table->index(['status', 'due_date']);
            $table->index('product_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
