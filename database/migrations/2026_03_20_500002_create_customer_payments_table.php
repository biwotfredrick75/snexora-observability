<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_no', 25)->unique();          // RCP/001/2026
            $table->string('debtor_no', 10);
            $table->foreign('debtor_no')->references('debtor_no')->on('customers');
            $table->date('payment_date');
            $table->string('bank_account_code', 20)->nullable(); // GL account code for the bank
            $table->string('payment_type', 20)->default('normal'); // normal, farmers, etc.
            $table->decimal('amount', 15, 2);                    // total received
            $table->decimal('discount_amount', 15, 2)->default(0); // prompt payment discount
            $table->decimal('bank_charge', 15, 2)->default(0);
            $table->decimal('allocated_amount', 15, 2)->default(0); // sum of allocations
            $table->decimal('unallocated_amount', 15, 2)->default(0); // remainder
            $table->string('reference', 60)->nullable();         // cheque / transfer ref
            $table->text('memo')->nullable();
            $table->enum('status', ['posted', 'cancelled'])->default('posted');
            $table->string('created_by', 50)->nullable();
            $table->timestamps();

            $table->index('debtor_no');
            $table->index('payment_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payments');
    }
};
