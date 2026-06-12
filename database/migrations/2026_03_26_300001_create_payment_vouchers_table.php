<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('pvn_no', 30)->unique();
            $table->unsignedInteger('supplier_id');
            $table->string('bank_account_code', 20)->nullable();
            $table->date('date_paid');
            $table->string('reference', 60)->nullable();
            $table->string('bank_cheque', 60)->nullable();
            $table->string('type', 20)->default('bank'); // bank|cash|cheque|mpesa
            $table->decimal('withholding_tax_amount', 15, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('cheque_no', 60)->nullable();
            $table->text('memo')->nullable();
            // draft → payables_approved → finance_approved → ceo_approved → posted
            $table->string('status', 30)->default('draft');
            $table->string('created_by', 40)->nullable();
            $table->timestamps();
            $table->index('supplier_id');
            $table->index('status');
        });

        Schema::create('payment_voucher_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_voucher_id')->constrained()->noActionOnDelete();
            $table->string('transaction_type', 30); // Supplier Invoice, GRN Receipt, Credit Note
            $table->unsignedBigInteger('transaction_id');
            $table->decimal('this_allocation', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_voucher_allocations');
        Schema::dropIfExists('payment_vouchers');
    }
};
