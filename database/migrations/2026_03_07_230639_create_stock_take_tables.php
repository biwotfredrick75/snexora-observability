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
        Schema::create('stock_takes', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('location_id');
            $table->date('date');
            $table->enum('status', ['draft', 'pending', 'approved', 'overdue'])->default('draft');
            $table->string('created_by')->nullable();
            $table->string('approved_by')->nullable();
            $table->text('memo')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_take_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_take_id')->constrained()->noActionOnDelete();
            $table->string('stock_id');
            $table->decimal('system_qty', 14, 6)->default(0);
            $table->decimal('counted_qty', 14, 6)->nullable();
            $table->string('unit')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_take_items');
        Schema::dropIfExists('stock_takes');
    }
};
