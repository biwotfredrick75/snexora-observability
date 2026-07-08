<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salary change audit trail for the HRM Compensation screen. Deliberately
 * separate from the Payroll module (payroll_pay_components / payroll_items),
 * which computes actual pay runs from the current employees.basic_salary —
 * this table only tracks *when and why* that figure changed over time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->decimal('previous_salary', 14, 2);
            $table->decimal('new_salary', 14, 2);
            $table->date('effective_date');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_revisions');
    }
};
