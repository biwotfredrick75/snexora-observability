<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Maps to legacy 0_purch_order_details
return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('milk_purch_order_details');
    }
};
