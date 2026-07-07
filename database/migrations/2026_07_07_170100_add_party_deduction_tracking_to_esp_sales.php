<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks whether an ESP sale's amount has been deducted from the BILLED
 * PARTY's own pay (farmer milk payment / employee payroll / grader payroll).
 *
 * This is deliberately separate from the existing `status`/`deducted_amount`/
 * `balance` fields, which track the UNRELATED lifecycle of the company
 * settling/paying the ESP provider itself (see EspController::postSettlement).
 * A sale can be settled with the provider before or after it has been
 * deducted from the party — the two events are independent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('esp_farmer_sales', function (Blueprint $table) {
            $table->boolean('party_deducted')->default(false)->after('balance');
            $table->date('party_deducted_at')->nullable()->after('party_deducted');
            $table->string('party_deducted_ref', 60)->nullable()->after('party_deducted_at');
            $table->index(['party_type', 'party_deducted']);
        });
    }

    public function down(): void
    {
        Schema::table('esp_farmer_sales', function (Blueprint $table) {
            $table->dropIndex(['party_type', 'party_deducted']);
            $table->dropColumn(['party_deducted', 'party_deducted_at', 'party_deducted_ref']);
        });
    }
};
