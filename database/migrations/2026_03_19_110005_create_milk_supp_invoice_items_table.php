<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Maps to legacy 0_supp_invoice_items
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milk_supp_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('supp_invoice_id');
            $table->unsignedBigInteger('grn_item_id');
            $table->unsignedBigInteger('po_detail_item_id')->nullable();
            $table->string('unique_key', 60)->nullable();
            $table->unsignedInteger('supp_trans_no')->default(0);
            $table->tinyInteger('supp_trans_type')->default(20);
            $table->string('gl_code', 20)->default('0');
            $table->string('stock_id', 20)->nullable();
            $table->string('description', 200)->default('');
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('unit_price', 12, 4)->default(0);
            $table->decimal('unit_tax', 12, 4)->default(0);
            $table->integer('dimension_id')->default(0);
            $table->integer('dimension2_id')->default(0);
            $table->string('tid', 50)->nullable();
            $table->timestamps();

            $table->foreign('purchase_id')->references('id')->on('milk_purchases')->noActionOnDelete();
            $table->foreign('supp_invoice_id')->references('id')->on('milk_supp_invoices')->noActionOnDelete();
            $table->foreign('grn_item_id')->references('id')->on('milk_grn_items')->noActionOnDelete();
            $table->index('supp_trans_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milk_supp_invoice_items');
    }
};
