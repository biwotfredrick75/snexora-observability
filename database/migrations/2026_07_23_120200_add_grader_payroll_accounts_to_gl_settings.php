<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gl_settings', function (Blueprint $table) {
            $table->string('grader_payroll_expense_account', 20)->nullable()->after('farmers_bank_account')
                ->comment('Grader/transporter collection-fee expense — DR on Close Payroll (gross pay + any unrecovered shortfall)');
            $table->string('graders_payable_account', 20)->nullable()->after('grader_payroll_expense_account')
                ->comment('Amount still owed to the grader after deductions — CR on Close Payroll, cleared later via Payment Voucher');
            $table->string('grader_deductions_recovery_account', 20)->nullable()->after('graders_payable_account')
                ->comment('ESP and shortage/transfer-loss deductions recovered via payroll — CR on Close Payroll');
        });
    }

    public function down(): void
    {
        Schema::table('gl_settings', function (Blueprint $table) {
            $table->dropColumn([
                'grader_payroll_expense_account',
                'graders_payable_account',
                'grader_deductions_recovery_account',
            ]);
        });
    }
};
