<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('cn_no', 25)->unique();
            $table->unsignedBigInteger('inv_id')->nullable();
            $table->foreign('inv_id')->references('id')->on('sales_invoices')->noActionOnDelete();
            $table->string('debtor_no', 10);
            $table->foreign('debtor_no')->references('debtor_no')->on('customers');
            $table->date('cn_date');
            $table->unsignedBigInteger('reason_id')->nullable();
            $table->foreign('reason_id')->references('id')->on('credit_note_reasons')->noActionOnDelete();
            $table->integer('location_id')->nullable();
            $table->decimal('sub_total', 15, 2)->default(0);
            $table->decimal('amount_total', 15, 2)->default(0);
            $table->enum('status', ['draft', 'placed', 'cancelled'])->default('draft');
            $table->text('comments')->nullable();
            $table->string('created_by', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('credit_note_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cn_id');
            $table->foreign('cn_id')->references('id')->on('credit_notes')->noActionOnDelete();
            $table->string('stock_id', 20);
            $table->string('description', 200);
            $table->decimal('qty', 12, 2);
            $table->string('unit', 20)->nullable();
            $table->decimal('price', 15, 4);
            $table->decimal('standard_cost', 15, 4)->default(0);
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->decimal('line_total', 15, 2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_items');
        Schema::dropIfExists('credit_notes');
    }
};
