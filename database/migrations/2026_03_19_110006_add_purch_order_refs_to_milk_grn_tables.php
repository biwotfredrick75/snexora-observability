<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Add cross-reference columns to existing milk GRN tables
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milk_grn_batches', function (Blueprint $table) {
            $table->unsignedBigInteger('purch_order_id')->nullable()->after('purchase_item_id');
            $table->string('tid', 50)->nullable()->after('loc_code');

            $table->foreign('purch_order_id')->references('id')->on('milk_purch_orders')->nullOnDelete();
        });

        Schema::table('milk_grn_items', function (Blueprint $table) {
            $table->unsignedBigInteger('po_detail_item_id')->nullable()->after('grn_batch_id');
            $table->string('tid', 50)->nullable()->after('unit_price');

            $table->foreign('po_detail_item_id')->references('id')->on('milk_purch_order_details')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('milk_grn_batches', function (Blueprint $table) {
            $table->dropForeign(['purch_order_id']);
            $table->dropColumn(['purch_order_id', 'tid']);
        });

        Schema::table('milk_grn_items', function (Blueprint $table) {
            $table->dropForeign(['po_detail_item_id']);
            $table->dropColumn(['po_detail_item_id', 'tid']);
        });
    }
};
