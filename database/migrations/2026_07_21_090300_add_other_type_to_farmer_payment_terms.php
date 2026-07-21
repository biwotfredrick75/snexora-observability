<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds an 'other' term type — payments run under a term of this type are
 * posted as an advance (farmer_payments type='advance') instead of a full
 * settlement, deferring the real deduction netting to the next standard
 * (end_of_month / unfiltered) batch run, which already recovers advances
 * via the existing $advancesMap mechanism in ProcessFarmerPaymentsBatch.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE farmer_payment_terms MODIFY type ENUM('prepayment', 'after_days', 'cash', 'end_of_month', 'other') NOT NULL DEFAULT 'after_days'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE farmer_payment_terms MODIFY type ENUM('prepayment', 'after_days', 'cash', 'end_of_month') NOT NULL DEFAULT 'after_days'");
    }
};
