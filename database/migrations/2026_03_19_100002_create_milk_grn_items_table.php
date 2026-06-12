<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milk_grn_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('grn_batch_id');
            $table->unsignedBigInteger('purchase_item_id');
            $table->string('unique_key', 60)->nullable();
            $table->string('item_code', 20)->nullable();
            $table->string('description', 200)->default('');
            $table->decimal('qty_received', 12, 3)->default(0);
            $table->decimal('qty_invoiced', 12, 3)->default(0);
            $table->decimal('unit_price', 12, 4)->default(0);
            $table->timestamps();

            $table->foreign('grn_batch_id')->references('id')->on('milk_grn_batches')->noActionOnDelete();
            $table->foreign('purchase_item_id')->references('id')->on('milk_purchase_items')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milk_grn_items');
    }
};
