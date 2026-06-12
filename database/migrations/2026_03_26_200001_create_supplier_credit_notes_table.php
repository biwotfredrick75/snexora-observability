<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('scn_no', 25)->unique();
            $table->unsignedInteger('supplier_id');
            $table->foreign('supplier_id')->references('supplierId')->on('suppliers');
            $table->date('date');
            $table->date('due_date')->nullable();
            $table->string('reference', 60)->nullable();
            $table->string('supplier_ref', 60)->nullable();
            $table->string('terms', 60)->nullable();
            $table->string('tax_group', 60)->nullable();
            $table->unsignedBigInteger('dimension_id')->nullable();
            $table->unsignedBigInteger('dimension2_id')->nullable();
            $table->decimal('items_total', 18, 4)->default(0);  // from delivery items
            $table->decimal('gl_total', 18, 4)->default(0);     // from GL items
            $table->decimal('total', 18, 4)->default(0);        // combined
            $table->text('memo')->nullable();
            $table->enum('status', ['draft', 'posted'])->default('draft');
            $table->string('raised_by', 50)->nullable();
            $table->timestamps();
        });

        // Delivery items — GRN items being returned/credited
        Schema::create('supplier_credit_note_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scn_id');
            $table->foreign('scn_id')->references('id')->on('supplier_credit_notes')->noActionOnDelete();
            $table->unsignedBigInteger('po_id')->nullable();    // source GRN purchase order
            $table->string('grn_no', 25)->nullable();
            $table->string('stock_id', 20);
            $table->string('description', 200)->nullable();
            $table->decimal('qty_received', 14, 4);
            $table->decimal('qty_invoiced', 14, 4)->default(0);
            $table->decimal('qty_to_credit', 14, 4);
            $table->decimal('price_before_tax', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->timestamps();
        });

        // GL items — direct journal entries on the credit note
        Schema::create('supplier_credit_note_gl_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scn_id');
            $table->foreign('scn_id')->references('id')->on('supplier_credit_notes')->noActionOnDelete();
            $table->string('account_code', 20);
            $table->string('account_name', 100)->nullable();
            $table->unsignedBigInteger('dimension_id')->nullable();
            $table->unsignedBigInteger('dimension2_id')->nullable();
            $table->decimal('amount', 18, 4)->default(0);
            $table->string('memo', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_credit_note_gl_items');
        Schema::dropIfExists('supplier_credit_note_items');
        Schema::dropIfExists('supplier_credit_notes');
    }
};
