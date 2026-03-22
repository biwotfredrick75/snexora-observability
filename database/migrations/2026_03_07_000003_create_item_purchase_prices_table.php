<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_purchase_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->string('supplier', 200);
            $table->decimal('price', 15, 4)->default(0);
            $table->string('currency', 50)->nullable();
            $table->string('supplier_uom', 50)->nullable();
            $table->decimal('conversion_factor', 15, 4)->default(1);
            $table->string('supplier_description', 200)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_purchase_prices');
    }
};
