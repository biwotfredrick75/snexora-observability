<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();

            // Our own idempotency key, exposed to the client — e.g. PMT/000123/2026
            $table->string('reference', 40)->unique();

            // 'mpesa_stk' | 'bank_transfer' | future channels — see config('payment.gateways')
            $table->string('channel', 20)->index();

            // pending -> completed | failed | cancelled | expired
            $table->string('status', 20)->default('pending')->index();

            $table->string('debtor_no', 10)->nullable()->index();
            $table->string('inv_no', 30)->nullable()->index();

            $table->decimal('amount', 15, 2);
            $table->string('phone', 15)->nullable();
            $table->string('bank_account_code', 20)->nullable();

            // Provider-side identifiers, normalised across gateways
            $table->string('provider_reference', 60)->nullable()->index(); // e.g. CheckoutRequestID
            $table->string('merchant_request_id', 60)->nullable();
            $table->string('provider_receipt', 60)->nullable();           // e.g. MpesaReceiptNumber / bank txn ref
            $table->string('result_code', 20)->nullable();
            $table->text('result_desc')->nullable();

            $table->unsignedBigInteger('payment_id')->nullable(); // links to customer_payments once completed
            $table->string('memo')->nullable();
            $table->string('initiated_by', 50)->nullable();

            $table->json('raw_request')->nullable();
            $table->json('raw_response')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('debtor_no')->references('debtor_no')->on('customers')->nullOnDelete();
            $table->foreign('payment_id')->references('id')->on('customer_payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
