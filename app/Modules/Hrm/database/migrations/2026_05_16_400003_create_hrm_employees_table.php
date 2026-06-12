<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hrm_employees', function (Blueprint $table) {
            $table->id();
            $table->string('emp_no', 30)->unique();

            // Identity
            $table->string('first_name', 60);
            $table->string('middle_name', 60)->nullable();
            $table->string('last_name', 60);
            $table->string('gender', 12)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('national_id', 30)->nullable()->unique();

            // Kenya statutory identifiers (consumed later by the Payroll module)
            $table->string('kra_pin', 20)->nullable();
            $table->string('nssf_no', 30)->nullable();
            $table->string('shif_no', 30)->nullable();
            $table->string('nita_no', 30)->nullable();
            $table->string('helb_no', 30)->nullable();

            // Contact
            $table->string('email', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('physical_address')->nullable();

            // Organisation (intra-module FKs)
            $table->foreignId('department_id')->nullable()
                ->constrained('hrm_departments')->noActionOnDelete();
            $table->foreignId('job_title_id')->nullable()
                ->constrained('hrm_job_titles')->noActionOnDelete();
            $table->unsignedBigInteger('manager_id')->nullable();

            // Optional auth link — Platform table, loose coupling, no DB-level FK
            $table->string('user_id', 60)->nullable();

            // Employment
            $table->string('employment_type', 20)->default('permanent'); // permanent|contract|casual|intern
            $table->date('hire_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('active'); // active|on_leave|suspended|terminated|inactive

            // Compensation / payment
            $table->decimal('basic_salary', 15, 2)->default(0);
            $table->string('payment_method', 20)->default('bank'); // bank|mpesa|cash
            $table->string('bank_name', 80)->nullable();
            $table->string('bank_branch', 80)->nullable();
            $table->string('bank_account', 40)->nullable();

            $table->string('photo_filename', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('department_id');
            $table->foreign('manager_id')->references('id')->on('hrm_employees')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_employees');
    }
};
