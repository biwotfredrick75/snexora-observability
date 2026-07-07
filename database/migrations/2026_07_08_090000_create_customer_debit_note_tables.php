<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Debit Note — the mirror image of Credit Notes (which already
 * exist): instead of reducing what a customer owes, this increases it
 * (e.g. an undercharge correction or a supplementary charge raised after
 * the original invoice), without needing a brand-new invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debit_note_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('reason_code', 20);
            $table->string('reason_name', 150);
            $table->string('description', 255)->nullable();
            $table->string('gl_account', 15)->nullable(); // income account this reason credits; falls back to gl_settings default
            $table->timestamps();
        });

        Schema::create('debit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('dn_no', 25)->unique();
            $table->unsignedBigInteger('inv_id')->nullable();
            $table->foreign('inv_id')->references('id')->on('sales_invoices')->noActionOnDelete();
            $table->string('debtor_no', 10);
            $table->foreign('debtor_no')->references('debtor_no')->on('customers');
            $table->date('dn_date');
            $table->unsignedBigInteger('reason_id')->nullable();
            $table->foreign('reason_id')->references('id')->on('debit_note_reasons')->noActionOnDelete();
            $table->integer('location_id')->nullable();
            $table->unsignedBigInteger('tax_type_id')->nullable();
            $table->integer('dimension_id')->nullable();
            $table->integer('dimension2_id')->nullable();
            $table->decimal('sub_total', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('amount_total', 15, 2)->default(0);
            $table->decimal('allocated_amount', 15, 2)->default(0);
            $table->decimal('unallocated_amount', 15, 2)->default(0);
            $table->enum('status', ['draft', 'placed', 'cancelled'])->default('draft');
            $table->text('comments')->nullable();
            $table->string('created_by', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('debit_note_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('dn_id');
            $table->foreign('dn_id')->references('id')->on('debit_notes')->noActionOnDelete();
            $table->string('stock_id', 20)->nullable();
            $table->string('description', 200);
            $table->decimal('qty', 12, 2)->default(1);
            $table->string('unit', 20)->nullable();
            $table->decimal('price', 15, 4);
            $table->decimal('line_total', 15, 2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debit_note_items');
        Schema::dropIfExists('debit_notes');
        Schema::dropIfExists('debit_note_reasons');
    }
};
