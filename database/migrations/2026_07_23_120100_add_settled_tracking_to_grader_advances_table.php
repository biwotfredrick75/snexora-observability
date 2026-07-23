<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors grader_deductions / esp_farmer_sales / farmer_direct_invoices —
 * a dedicated settled/settled_at/settled_ref trio rather than overloading
 * an existing column. Lets GraderPayrollController::close() mark an
 * advance as recovered so it isn't pulled into a future period's
 * net_payable calculation again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grader_advances', function (Blueprint $table) {
            $table->boolean('settled')->default(false)->after('notes');
            $table->date('settled_at')->nullable()->after('settled');
            $table->string('settled_ref', 60)->nullable()->after('settled_at');
        });
    }

    public function down(): void
    {
        Schema::table('grader_advances', function (Blueprint $table) {
            $table->dropColumn(['settled', 'settled_at', 'settled_ref']);
        });
    }
};
