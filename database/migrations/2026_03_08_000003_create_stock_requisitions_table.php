<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->string('from_location_id', 30)->nullable();
            $table->string('to_location_id', 30)->nullable();
            $table->date('date');
            $table->string('person', 100)->nullable();
            $table->string('gl_account', 15)->nullable();
            $table->text('memo')->nullable();
            $table->string('status', 20)->default('draft'); // draft | pending | approved | rejected | dispatched | received
            $table->string('raised_by', 30)->nullable();
            $table->string('approved_by', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('stock_requisition_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained('stock_requisitions')->noActionOnDelete();
            $table->string('stock_id', 20);
            $table->decimal('quantity', 15, 4)->default(0);
            $table->string('unit', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_requisition_items');
        Schema::dropIfExists('stock_requisitions');
    }
};
