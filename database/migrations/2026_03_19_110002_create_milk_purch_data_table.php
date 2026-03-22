<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Maps to legacy 0_purch_data — supplier purchase price data per transaction
return new class extends Migration
{
    public function up(): void
    {
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

            $table->foreign('purchase_item_id')->references('id')->on('milk_purchase_items')->cascadeOnDelete();
            $table->index('unique_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milk_purch_data');
    }
};
