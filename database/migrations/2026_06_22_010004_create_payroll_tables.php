<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Note: employees themselves live in the `employees` table owned by the
        // HRM module (App\Models\Employee / create_hrm_employees_table migration)
        // — Payroll consumes that table, it doesn't own employee master data.

        // Pay component catalogue — allowances and deductions, including the four
        // statutory deductions (computed by PayrollStatutoryService, not user-edited).
        Schema::create('payroll_pay_components', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->enum('category', ['allowance', 'deduction'])->default('allowance');
            $table->enum('computation_type', [
                'fixed', 'percentage_of_basic',
                'statutory_paye', 'statutory_shif', 'statutory_nssf', 'statutory_housing_levy',
            ])->default('fixed');
            $table->decimal('default_amount', 10, 2)->default(0); // used when computation_type = fixed
            $table->decimal('percentage', 6, 3)->default(0);      // used when computation_type = percentage_of_basic
            $table->boolean('is_taxable')->default(true);         // allowances: counts toward PAYE taxable pay
            $table->boolean('is_statutory')->default(false);      // protects amount from manual edit; auto-managed
            $table->unsignedBigInteger('gl_account_id')->nullable();
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('gl_account_id')->references('id')->on('gl_accounts')->nullOnDelete();
        });

        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->string('ref_no', 50)->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('status', ['draft', 'generated', 'approved', 'posted'])->default('draft');
            $table->decimal('total_gross', 15, 2)->default(0);
            $table->decimal('total_paye', 15, 2)->default(0);
            $table->decimal('total_shif', 15, 2)->default(0);
            $table->decimal('total_nssf', 15, 2)->default(0);
            $table->decimal('total_housing_levy', 15, 2)->default(0);
            $table->decimal('total_deductions', 15, 2)->default(0);
            $table->decimal('total_net', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained('payroll_periods')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->decimal('basic_salary', 12, 2)->default(0);
            $table->decimal('gross_pay', 15, 2)->default(0);
            $table->decimal('taxable_pay', 15, 2)->default(0);
            $table->decimal('paye', 15, 2)->default(0);
            $table->decimal('shif', 15, 2)->default(0);
            $table->decimal('nssf', 15, 2)->default(0);
            $table->decimal('housing_levy', 15, 2)->default(0);
            $table->decimal('other_allowances', 15, 2)->default(0);
            $table->decimal('manual_allowances', 15, 2)->default(0);
            $table->decimal('other_deductions', 15, 2)->default(0);
            $table->decimal('manual_deductions', 15, 2)->default(0);
            $table->decimal('net_pay', 15, 2)->default(0);
            $table->enum('payment_status', ['pending', 'paid'])->default('pending');
            $table->string('payment_ref', 100)->nullable();
            $table->timestamps();

            $table->unique(['payroll_period_id', 'employee_id']);
        });

        // Per-period postings: one row per employee per pay component per period
        Schema::create('payroll_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained('payroll_periods')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('component_id')->constrained('payroll_pay_components')->cascadeOnDelete();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('note', 255)->nullable();
            $table->boolean('is_auto')->default(true);
            $table->timestamps();

            $table->unique(['payroll_period_id', 'employee_id', 'component_id'], 'payroll_postings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_postings');
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payroll_periods');
        Schema::dropIfExists('payroll_pay_components');
    }
};
