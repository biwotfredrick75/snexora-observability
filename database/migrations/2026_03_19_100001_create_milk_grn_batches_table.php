<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milk_grn_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('purchase_item_id');
            $table->string('unique_key', 60)->nullable();
            $table->unsignedInteger('supplier_id')->nullable();
            $table->string('reference', 100)->nullable();
            $table->date('delivery_date');
            $table->string('loc_code', 20)->default('');
            $table->timestamps();

            $table->foreign('purchase_id')->references('id')->on('milk_purchases')->cascadeOnDelete();
            $table->foreign('purchase_item_id')->references('id')->on('milk_purchase_items')->cascadeOnDelete();
            $table->index('unique_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milk_grn_batches');
    }
};
