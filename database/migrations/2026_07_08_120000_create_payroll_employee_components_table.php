<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-employee earnings/deductions — e.g. a loan repayment for one
        // employee or a one-off allowance — layered on top of the company-wide
        // payroll_pay_components catalogue. Picked up automatically by
        // PayrollGenerationService each period it is active for.
        Schema::create('payroll_employee_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('payroll_pay_components')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->boolean('is_recurring')->default(true);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedInteger('total_installments')->nullable();
            $table->unsignedInteger('installments_used')->default(0);
            $table->boolean('active')->default(true);
            $table->string('notes', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_employee_components');
    }
};
