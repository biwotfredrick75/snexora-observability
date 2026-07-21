<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gl_settings', function (Blueprint $table) {
            $table->string('sacco_savings_liability_account', 20)->nullable()
                ->after('default_tare_kg')
                ->comment('CR on member savings deposit — what the SACCO owes members');
            $table->string('sacco_share_capital_account', 20)->nullable()
                ->after('sacco_savings_liability_account')
                ->comment('CR on share purchase — member equity in the SACCO');
            $table->string('sacco_loans_receivable_account', 20)->nullable()
                ->after('sacco_share_capital_account')
                ->comment('DR on loan disbursement, CR on repayment — what members owe the SACCO');
            $table->string('sacco_loan_interest_income_account', 20)->nullable()
                ->after('sacco_loans_receivable_account')
                ->comment('CR portion of a loan repayment representing interest income');
            $table->string('sacco_cash_account', 20)->nullable()
                ->after('sacco_loan_interest_income_account')
                ->comment('DR/CR counter-leg for cash/bank-settled SACCO transactions');
        });
    }

    public function down(): void
    {
        Schema::table('gl_settings', function (Blueprint $table) {
            $table->dropColumn([
                'sacco_savings_liability_account',
                'sacco_share_capital_account',
                'sacco_loans_receivable_account',
                'sacco_loan_interest_income_account',
                'sacco_cash_account',
            ]);
        });
    }
};
