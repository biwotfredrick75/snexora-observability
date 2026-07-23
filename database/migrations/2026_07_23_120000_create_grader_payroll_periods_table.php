<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per "Close Payroll" run — either a single grader/station
 * (grader_id set) or all graders together (grader_id null) for a given
 * month/year. Its existence is what GraderPayrollController::close()
 * checks to refuse re-posting the same (or an overlapping) period, and
 * what gl_trans_no ties back to in gld_transactions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grader_payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('month');
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('grader_id')->nullable();   // null = all graders
            $table->string('reference', 30);
            $table->decimal('total_gross', 15, 2)->default(0);
            $table->decimal('total_advance', 15, 2)->default(0);
            $table->decimal('total_esp', 15, 2)->default(0);
            $table->decimal('total_shortage', 15, 2)->default(0);
            $table->decimal('total_balance', 15, 2)->default(0);
            $table->decimal('net_payable', 15, 2)->default(0);
            $table->unsignedInteger('gl_trans_no')->nullable();
            $table->string('posted_by', 100)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->foreign('grader_id')->references('id')->on('inventory_locations')->noActionOnDelete();
            $table->index(['month', 'year', 'grader_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grader_payroll_periods');
    }
};
