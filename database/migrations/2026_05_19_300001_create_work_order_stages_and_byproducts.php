<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_stages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('work_order_id');
            $table->foreign('work_order_id')->references('id')->on('work_orders')->noActionOnDelete();
            $table->unsignedTinyInteger('stage_order');
            $table->string('stage_code', 50);
            $table->string('stage_name', 100);
            $table->boolean('produces_byproducts')->default(false);
            $table->boolean('is_final')->default(false);
            $table->string('status', 20)->default('pending'); // pending|active|completed|skipped
            $table->text('notes')->nullable();
            $table->json('qa_data')->nullable();
            $table->string('started_by', 100)->nullable();
            $table->string('completed_by', 100)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('work_order_byproducts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('work_order_id');
            $table->foreign('work_order_id')->references('id')->on('work_orders')->noActionOnDelete();
            $table->unsignedBigInteger('work_order_stage_id')->nullable();
            $table->foreign('work_order_stage_id')->references('id')->on('work_order_stages')->onDelete('set null');
            $table->string('stage_code', 50);
            $table->string('stage_name', 100);
            $table->string('stock_id', 50);
            $table->string('description', 200)->nullable();
            $table->decimal('qty', 15, 4);
            $table->string('unit', 30)->nullable();
            $table->string('location_code', 200)->nullable();
            $table->string('registered_by', 100)->nullable();
            $table->unsignedBigInteger('stock_movement_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_byproducts');
        Schema::dropIfExists('work_order_stages');
    }
};
