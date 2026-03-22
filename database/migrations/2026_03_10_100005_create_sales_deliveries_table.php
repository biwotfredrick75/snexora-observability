<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('dn_no', 20)->unique();
            $table->string('debtor_no', 10);
            $table->foreign('debtor_no')->references('debtor_no')->on('customers');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->foreign('branch_id')->references('id')->on('customer_branches')->nullOnDelete();
            $table->date('delivery_date');
            $table->date('invoice_before')->nullable();
            $table->integer('payment_terms')->nullable();
            $table->integer('price_list_id')->nullable();
            $table->decimal('shipping_charge', 15, 2)->default(0);
            $table->decimal('sub_total', 15, 2)->default(0);
            $table->decimal('amount_total', 15, 2)->default(0);
            $table->enum('status', ['draft', 'placed', 'cancelled'])->default('draft');
            $table->integer('dimension_id')->nullable();
            $table->integer('dimension2_id')->nullable();
            $table->integer('location_id')->nullable();
            $table->string('vehicle', 50)->nullable();
            $table->string('shift', 20)->nullable();
            $table->string('deliver_to', 100)->nullable();
            $table->text('address')->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('customer_ref', 60)->nullable();
            $table->text('comments')->nullable();
            $table->integer('shipping_company_id')->nullable();
            $table->string('created_by', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('sales_delivery_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('delivery_id');
            $table->foreign('delivery_id')->references('id')->on('sales_deliveries')->onDelete('cascade');
            $table->string('stock_id', 20);
            $table->string('description', 200);
            $table->decimal('qty', 12, 2);
            $table->string('unit', 20)->nullable();
            $table->decimal('price', 15, 4);
            $table->decimal('standard_cost', 15, 4)->default(0);
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->decimal('line_total', 15, 2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_delivery_items');
        Schema::dropIfExists('sales_deliveries');
    }
};
