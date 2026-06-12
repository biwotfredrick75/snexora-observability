<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RFQ header — one quotation request can be sent to multiple suppliers
        Schema::create('purchase_quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quotation_no', 20)->unique();
            $table->unsignedBigInteger('pr_id')->nullable()->index(); // source PR
            $table->string('reference', 100)->default('');
            $table->date('quotation_date');
            $table->date('due_date')->nullable();
            $table->string('location_id', 30)->default('');
            $table->string('status', 20)->default('draft');
            // draft → dispatched → received → ranked → converted
            $table->string('raised_by', 50)->default('');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Items required (from PR or entered manually)
        Schema::create('purchase_quotation_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('quotation_id');
            $table->foreign('quotation_id')->references('id')->on('purchase_quotations')->noActionOnDelete();
            $table->string('stock_id', 20);
            $table->string('description', 255)->default('');
            $table->decimal('qty_required', 14, 4);
            $table->string('unit', 20)->default('');
        });

        // Suppliers invited to quote — one row per supplier per RFQ
        Schema::create('purchase_quotation_suppliers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('quotation_id');
            $table->foreign('quotation_id')->references('id')->on('purchase_quotations')->noActionOnDelete();
            $table->unsignedInteger('supplier_id')->index();
            $table->string('status', 20)->default('pending'); // pending → received → selected
            $table->date('received_date')->nullable();
            $table->decimal('total_amount', 14, 4)->default(0);
            $table->text('notes')->nullable();
        });

        // Per-item prices from each supplier
        Schema::create('purchase_quotation_item_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('quotation_supplier_id');
            $table->foreign('quotation_supplier_id')->references('id')->on('purchase_quotation_suppliers')->noActionOnDelete();
            $table->string('stock_id', 20);
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->decimal('discount_amt', 14, 4)->default(0);
            $table->decimal('line_total', 14, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_quotation_item_prices');
        Schema::dropIfExists('purchase_quotation_suppliers');
        Schema::dropIfExists('purchase_quotation_items');
        Schema::dropIfExists('purchase_quotations');
    }
};
