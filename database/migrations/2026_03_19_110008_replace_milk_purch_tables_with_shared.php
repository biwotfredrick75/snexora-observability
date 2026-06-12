<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Drop the milk-specific purch tables (now using shared purchase_orders,
// purchase_order_items, purch_data) and rewire FKs accordingly.
return new class extends Migration
{
    public function up(): void
    {
        // Drop FKs from 110006 that reference the tables we're about to drop.
        Schema::table('milk_grn_items', function (Blueprint $table) {
            $table->dropForeign(['po_detail_item_id']);
        });
        Schema::table('milk_grn_batches', function (Blueprint $table) {
            $table->dropForeign(['purch_order_id']);
        });

        // Drop the now-redundant milk-specific purch tables.
        Schema::dropIfExists('milk_purch_order_details');
        Schema::dropIfExists('milk_purch_orders');
        Schema::dropIfExists('milk_purch_data');
    }

    public function down(): void
    {
        // Recreate milk_purch_data
        Schema::create('milk_purch_data', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_item_id');
            $table->string('unique_key', 60)->nullable();
            $table->unsignedInteger('supplier_id')->nullable();
            $table->string('stock_id', 20)->nullable();
            $table->decimal('price', 12, 4)->default(0);
            $table->string('suppliers_uom', 10)->default('L');
            $table->decimal('conversion_factor', 10, 4)->default(1);
            $table->string('supplier_description', 200)->default('');
            $table->string('tid', 50)->nullable();
            $table->timestamps();
            $table->foreign('purchase_item_id')->references('id')->on('milk_purchase_items')->noActionOnDelete();
        });

        // Recreate milk_purch_orders
        Schema::create('milk_purch_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('purchase_item_id');
            $table->string('unique_key', 60)->nullable();
            $table->unsignedInteger('supplier_id')->nullable();
            $table->unsignedInteger('shift_id')->nullable();
            $table->string('shift', 50)->default('');
            $table->string('user_id', 50)->default('');
            $table->string('route_id', 20)->default('');
            $table->date('ord_date');
            $table->string('into_stock_location', 20)->default('');
            $table->decimal('total', 12, 3)->default(0);
            $table->string('reference', 100)->nullable();
            $table->string('random_identifier', 100)->nullable();
            $table->string('comments', 200)->default('');
            $table->string('source_from', 50)->default('Manual');
            $table->string('tid', 50)->nullable();
            $table->timestamps();
            $table->foreign('purchase_id')->references('id')->on('milk_purchases')->noActionOnDelete();
            $table->foreign('purchase_item_id')->references('id')->on('milk_purchase_items')->noActionOnDelete();
        });

        // Recreate milk_purch_order_details
        Schema::create('milk_purch_order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purch_order_id');
            $table->unsignedBigInteger('purchase_item_id');
            $table->string('unique_key', 60)->nullable();
            $table->string('route_id', 20)->default('');
            $table->string('shift', 50)->default('');
            $table->string('item_code', 20)->nullable();
            $table->string('description', 200)->default('');
            $table->date('delivery_date');
            $table->decimal('qty_invoiced', 12, 3)->default(0);
            $table->decimal('unit_price', 12, 4)->default(0);
            $table->decimal('act_price', 12, 4)->default(0);
            $table->decimal('std_cost_unit', 12, 4)->default(0);
            $table->decimal('quantity_ordered', 12, 3)->default(0);
            $table->decimal('quantity_received', 12, 3)->default(0);
            $table->string('tid', 50)->nullable();
            $table->timestamps();
            $table->foreign('purch_order_id')->references('id')->on('milk_purch_orders')->noActionOnDelete();
            $table->foreign('purchase_item_id')->references('id')->on('milk_purchase_items')->noActionOnDelete();
        });

        // Restore FKs to the milk-specific tables
        Schema::table('milk_supp_invoice_items', function (Blueprint $table) {
            $table->dropForeign(['po_detail_item_id']);
            $table->foreign('po_detail_item_id')->references('id')->on('milk_purch_order_details')->noActionOnDelete();
        });

        Schema::table('milk_grn_items', function (Blueprint $table) {
            $table->dropForeign(['po_detail_item_id']);
            $table->foreign('po_detail_item_id')->references('id')->on('milk_purch_order_details')->noActionOnDelete();
        });

        Schema::table('milk_grn_batches', function (Blueprint $table) {
            $table->dropForeign(['purch_order_id']);
            $table->foreign('purch_order_id')->references('id')->on('milk_purch_orders')->noActionOnDelete();
        });
    }
};
