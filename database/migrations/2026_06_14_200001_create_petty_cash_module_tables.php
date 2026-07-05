<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('petty_cash_funds')) {
            Schema::create('petty_cash_funds', function (Blueprint $table) {
                $table->id();
                $table->string('fund_code', 20)->unique();
                $table->string('name', 100);
                $table->string('description', 255)->nullable();
                $table->string('gl_account_code', 20);
                $table->decimal('imprest_amount', 15, 2)->default(0);
                $table->decimal('current_balance', 15, 2)->default(0);
                $table->decimal('transaction_limit', 15, 2)->nullable();
                $table->tinyInteger('low_balance_pct')->default(25);
                $table->string('custodian_user_id', 60)->nullable();
                $table->string('backup_custodian_user_id', 60)->nullable();
                $table->string('cost_center', 100)->nullable();
                $table->string('currency', 10)->default('KES');
                $table->string('status', 20)->default('active');
                $table->timestamps();

                $table->index('status');
                $table->index('custodian_user_id');
            });
        }

        if (! Schema::hasTable('petty_cash_vouchers')) {
            Schema::create('petty_cash_vouchers', function (Blueprint $table) {
                $table->id();
                $table->string('voucher_no', 30)->unique();
                $table->unsignedBigInteger('fund_id');
                $table->date('voucher_date');
                $table->string('payee', 150);
                $table->string('expense_account_code', 20);
                $table->text('description');
                $table->decimal('amount', 15, 2);
                $table->integer('approval_tier')->default(1);
                $table->string('status', 20)->default('pending');
                $table->string('receipt_path', 500)->nullable();
                $table->string('created_by', 60);
                $table->string('approved_by', 60)->nullable();
                $table->dateTime('approved_at')->nullable();
                $table->text('approval_notes')->nullable();
                $table->string('rejected_by', 60)->nullable();
                $table->dateTime('rejected_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->string('voided_by', 60)->nullable();
                $table->dateTime('voided_at')->nullable();
                $table->text('void_reason')->nullable();
                $table->integer('gl_trans_no')->nullable();
                $table->boolean('replenished')->default(false);
                $table->timestamps();

                $table->index('status');
                $table->index('voucher_date');
                $table->index('fund_id');
                $table->foreign('fund_id')->references('id')->on('petty_cash_funds')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('petty_cash_reconciliations')) {
            Schema::create('petty_cash_reconciliations', function (Blueprint $table) {
                $table->id();
                $table->string('recon_no', 30)->unique();
                $table->unsignedBigInteger('fund_id');
                $table->date('recon_date');
                $table->decimal('expected_balance', 15, 2);
                $table->decimal('vouchers_total', 15, 2);
                $table->decimal('cash_counted', 15, 2);
                $table->decimal('variance', 15, 2)->default(0);
                $table->boolean('is_surprise_audit')->default(false);
                $table->string('status', 20)->default('draft');
                $table->string('created_by', 60);
                $table->dateTime('custodian_signed_at')->nullable();
                $table->string('supervisor_id', 60)->nullable();
                $table->dateTime('supervisor_signed_at')->nullable();
                $table->integer('variance_gl_trans_no')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index('fund_id');
                $table->index('recon_date');
                $table->foreign('fund_id')->references('id')->on('petty_cash_funds')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('petty_cash_replenishments')) {
            Schema::create('petty_cash_replenishments', function (Blueprint $table) {
                $table->id();
                $table->string('repl_no', 30)->unique();
                $table->unsignedBigInteger('fund_id');
                $table->date('request_date');
                $table->string('requested_by', 60);
                $table->decimal('amount_requested', 15, 2);
                $table->integer('vouchers_count')->default(0);
                $table->string('bank_account_code', 20)->nullable();
                $table->string('status', 20)->default('pending');
                $table->string('approved_by', 60)->nullable();
                $table->dateTime('approved_at')->nullable();
                $table->string('payment_reference', 100)->nullable();
                $table->date('payment_date')->nullable();
                $table->string('confirmed_by', 60)->nullable();
                $table->dateTime('confirmed_at')->nullable();
                $table->integer('gl_trans_no')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index('fund_id');
                $table->index('status');
                $table->foreign('fund_id')->references('id')->on('petty_cash_funds')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_replenishments');
        Schema::dropIfExists('petty_cash_reconciliations');
        Schema::dropIfExists('petty_cash_vouchers');
        Schema::dropIfExists('petty_cash_funds');
    }
};
