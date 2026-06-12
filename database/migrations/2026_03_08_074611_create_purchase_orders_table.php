<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_no', 30)->unique();
            $table->string('type', 20)->default('po');           // po|grn|invoice
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('reference', 60)->default('');
            $table->string('supplier_reference', 60)->default('');
            $table->date('order_date');
            $table->date('delivery_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('location_id', 30)->default('');      // deliver to
            $table->string('receive_into', 30)->default('');
            $table->string('payment_terms', 50)->default('cash');
            $table->string('currency', 10)->default('KES');
            $table->decimal('exchange_rate', 14, 6)->default(1);
            $table->string('status', 30)->default('draft');      // draft|submitted|hod_approved|finance_approved|ceo_approved|rejected|received
            $table->string('raised_by', 60)->default('');
            $table->string('hod_approval_by', 60)->nullable();
            $table->string('finance_approval_by', 60)->nullable();
            $table->string('ceo_approval_by', 60)->nullable();
            $table->date('hod_approval_date')->nullable();
            $table->date('finance_approval_date')->nullable();
            $table->date('ceo_approval_date')->nullable();
            $table->decimal('sub_total', 18, 4)->default(0);
            $table->decimal('amount_total', 18, 4)->default(0);
            $table->text('customer_memo')->nullable();
            $table->text('internal_memo')->nullable();
            // supplier_id references suppliers.supplierId (unsigned int PK)
            $table->index('supplier_id');
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('po_id');
            $table->string('stock_id', 20)->default('');
            $table->string('description', 200)->default('');
            $table->decimal('qty', 14, 4)->default(0);
            $table->string('unit', 30)->default('');
            $table->date('required_delivery_date')->nullable();
            $table->decimal('price_before_tax', 18, 4)->default(0);
            $table->decimal('discount_amt', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->foreign('po_id')->references('id')->on('purchase_orders')->noActionOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
