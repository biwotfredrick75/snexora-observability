<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * checkoff_services.service_type was always meant to be a Deduction/Earning
 * direction flag (CheckoffServiceController already validates it as
 * required|in:Deduction,Claim), but rows inserted outside that controller
 * (CheckoffServiceSeeder, SaccoController::postCheckoffForPeriod) used
 * unrelated category labels (Loan, Insurance, Savings, Input, Welfare,
 * sacco_loan) instead. Normalize everything to the two real values so the
 * field is reliable as a direction indicator, and rename Claim -> Earning
 * to say what it means.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('checkoff_services')->where('service_type', 'Claim')->update(['service_type' => 'Earning']);

        DB::table('checkoff_services')
            ->where(function ($q) {
                $q->whereNull('service_type')->orWhereNotIn('service_type', ['Deduction', 'Earning']);
            })
            ->update(['service_type' => 'Deduction']);
    }

    public function down(): void
    {
        DB::table('checkoff_services')->where('service_type', 'Earning')->update(['service_type' => 'Claim']);
    }
};
