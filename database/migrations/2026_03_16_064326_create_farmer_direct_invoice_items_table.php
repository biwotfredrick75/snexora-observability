<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farmer_direct_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->index();
            $table->string('stock_id', 20);
            $table->string('description', 200);
            $table->decimal('qty', 12, 4)->default(1);
            $table->decimal('qty_required', 12, 4)->default(1);
            $table->string('unit', 20)->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->decimal('price', 14, 4)->default(0);
            $table->decimal('discount_pct', 6, 2)->default(0);
            $table->decimal('standard_cost', 14, 4)->default(0);
            $table->decimal('line_total', 14, 4)->default(0);
            $table->timestamps();

            $table->foreign('invoice_id')->references('id')->on('farmer_direct_invoices')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farmer_direct_invoice_items');
    }
};
