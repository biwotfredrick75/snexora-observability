<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milk_grader_collections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('purchase_item_id');
            $table->unsignedBigInteger('grn_batch_id');
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('rate', 12, 4)->default(1);
            $table->string('location', 20)->default('');
            $table->date('date_collected');
            $table->string('grader_id', 20)->default('');
            $table->string('price_list_id', 20)->default('');
            $table->timestamps();

            $table->foreign('purchase_id')->references('id')->on('milk_purchases')->noActionOnDelete();
            $table->foreign('purchase_item_id')->references('id')->on('milk_purchase_items')->noActionOnDelete();
            $table->foreign('grn_batch_id')->references('id')->on('milk_grn_batches')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milk_grader_collections');
    }
};
