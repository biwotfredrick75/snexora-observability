<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_no', 30)->unique();
            $table->string('cashier_id', 30)->nullable();
            $table->string('customer_name', 120)->default('Walk-in Customer');
            $table->decimal('subtotal',      15, 2)->default(0);
            $table->decimal('tax_amount',    15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('total_amount',  15, 2)->default(0);
            $table->enum('payment_method', ['cash', 'card', 'mpesa'])->default('cash');
            $table->decimal('amount_tendered', 15, 2)->default(0);
            $table->decimal('change_amount',   15, 2)->default(0);
            $table->string('mpesa_ref', 60)->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['completed', 'voided'])->default('completed');
            $table->string('voided_by', 30)->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 200)->nullable();
            $table->timestamps();
        });

        Schema::create('pos_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_transaction_id')->constrained()->cascadeOnDelete();
            $table->string('stock_id', 20);
            $table->string('description', 200);
            $table->decimal('unit_price',      12, 4)->default(0);
            $table->decimal('quantity',        12, 4)->default(1);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('tax_rate',         5, 2)->default(0);
            $table->decimal('tax_amount',      12, 4)->default(0);
            $table->decimal('line_total',      15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_transaction_items');
        Schema::dropIfExists('pos_transactions');
    }
};
