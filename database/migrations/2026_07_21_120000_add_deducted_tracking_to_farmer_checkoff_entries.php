<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * farmer_checkoff_entries had no way to record that an entry had already
 * been deducted from a farmer's pay by a batch — so re-running (or
 * reposting) a batch for the same period double-deducted every checkoff
 * service, SACCO loan repayments included. Mirrors the settled/settled_at/
 * settled_ref pattern already used on farmer_direct_invoices and
 * party_deducted on esp_farmer_sales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farmer_checkoff_entries', function (Blueprint $table) {
            $table->boolean('deducted')->default(false)->after('amount');
            $table->date('deducted_at')->nullable()->after('deducted');
            $table->string('deducted_ref', 60)->nullable()->after('deducted_at');
        });
    }

    public function down(): void
    {
        Schema::table('farmer_checkoff_entries', function (Blueprint $table) {
            $table->dropColumn(['deducted', 'deducted_at', 'deducted_ref']);
        });
    }
};
