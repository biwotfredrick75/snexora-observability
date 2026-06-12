<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Maps to legacy 0_purch_orders — one record per farmer item created on approval
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milk_purch_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('purchase_item_id');
            $table->string('unique_key', 60)->nullable();
            $table->unsignedInteger('supplier_id')->nullable();
            $table->unsignedInteger('shift_id')->nullable();
            $table->string('shift', 50)->default('');
            $table->string('user_id', 50)->default('');
            $table->string('route_id', 20)->default('');     // route code
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
            $table->index('unique_key');
            $table->index('random_identifier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milk_purch_orders');
    }
};
