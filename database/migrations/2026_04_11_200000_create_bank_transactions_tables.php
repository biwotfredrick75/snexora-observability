<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bank Payments (money going OUT of a bank account)
        Schema::create('bank_payments', function (Blueprint $table) {
            $table->id();
            $table->string('pay_no', 30)->unique();
            $table->string('bank_account_code', 20);
            $table->string('pay_to', 150)->nullable();
            $table->date('payment_date');
            $table->string('reference', 80)->nullable();
            $table->string('cheque_no', 40)->nullable();
            $table->decimal('exchange_rate', 12, 6)->default(1);
            $table->decimal('amount', 15, 2)->default(0);
            $table->text('memo')->nullable();
            $table->string('status', 20)->default('posted'); // posted | voided
            $table->string('created_by', 60)->nullable();
            $table->timestamps();

            $table->index('bank_account_code');
            $table->index('payment_date');
        });

        Schema::create('bank_payment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('bank_payments')->cascadeOnDelete();
            $table->string('account_code', 20);
            $table->unsignedBigInteger('dimension_id')->nullable();
            $table->unsignedBigInteger('dimension2_id')->nullable();
            $table->unsignedBigInteger('tax_type_id')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('narration', 255)->nullable();
        });

        // Bank Deposits (money coming INTO a bank account)
        Schema::create('bank_deposits', function (Blueprint $table) {
            $table->id();
            $table->string('dep_no', 30)->unique();
            $table->string('bank_account_code', 20);
            $table->string('deposit_type', 60)->default('Miscellaneous');
            $table->date('deposit_date');
            $table->string('reference', 80)->nullable();
            $table->string('cheque_no', 40)->nullable();
            $table->decimal('exchange_rate', 12, 6)->default(1);
            $table->decimal('amount', 15, 2)->default(0);
            $table->text('memo')->nullable();
            $table->string('status', 20)->default('posted');
            $table->string('created_by', 60)->nullable();
            $table->timestamps();

            $table->index('bank_account_code');
            $table->index('deposit_date');
        });

        Schema::create('bank_deposit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposit_id')->constrained('bank_deposits')->cascadeOnDelete();
            $table->string('account_code', 20);
            $table->unsignedBigInteger('dimension_id')->nullable();
            $table->unsignedBigInteger('dimension2_id')->nullable();
            $table->unsignedBigInteger('tax_type_id')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('narration', 255)->nullable();
        });

        // Bank Transfers (inter-account)
        Schema::create('bank_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_no', 30)->unique();
            $table->string('from_account_code', 20);
            $table->string('to_account_code', 20);
            $table->date('transfer_date');
            $table->string('reference', 80)->nullable();
            $table->decimal('amount', 15, 2);
            $table->decimal('bank_charge', 15, 2)->default(0);
            $table->string('cheque_no', 40)->nullable();
            $table->text('memo')->nullable();
            $table->string('status', 20)->default('posted');
            $table->string('created_by', 60)->nullable();
            $table->timestamps();

            $table->index('from_account_code');
            $table->index('transfer_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_payment_items');
        Schema::dropIfExists('bank_payments');
        Schema::dropIfExists('bank_deposit_items');
        Schema::dropIfExists('bank_deposits');
        Schema::dropIfExists('bank_transfers');
    }
};
