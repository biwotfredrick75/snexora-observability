<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * farmer_payments (type=advance) had no way to record that an advance had
 * already been recovered from a farmer's pay by a settlement batch — so a
 * recovered advance kept showing as an outstanding deduction in every
 * later batch/report indefinitely. Mirrors the deducted/deducted_at/
 * deducted_ref pattern just added to farmer_checkoff_entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farmer_payments', function (Blueprint $table) {
            $table->boolean('recovered')->default(false)->after('amount_payment');
            $table->date('recovered_at')->nullable()->after('recovered');
            $table->string('recovered_ref', 60)->nullable()->after('recovered_at');
        });
    }

    public function down(): void
    {
        Schema::table('farmer_payments', function (Blueprint $table) {
            $table->dropColumn(['recovered', 'recovered_at', 'recovered_ref']);
        });
    }
};
