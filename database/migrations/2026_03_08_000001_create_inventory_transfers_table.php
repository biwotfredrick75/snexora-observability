<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->string('from_location_id', 30)->nullable();
            $table->string('to_location_id', 30)->nullable();
            $table->date('date');
            $table->string('vehicle', 50)->nullable();
            $table->string('shift', 20)->nullable();
            $table->unsignedBigInteger('dimension_id')->nullable();
            $table->unsignedBigInteger('dimension2_id')->nullable();
            $table->string('gl_account', 15)->nullable();
            $table->text('memo')->nullable();
            $table->string('status', 20)->default('draft'); // draft | pending | approved | rejected
            $table->string('created_by', 30)->nullable();
            $table->string('approved_by', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('inventory_transfers')->noActionOnDelete();
            $table->string('stock_id', 20);
            $table->decimal('quantity', 15, 4)->default(0);
            $table->string('unit', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_items');
        Schema::dropIfExists('inventory_transfers');
    }
};
