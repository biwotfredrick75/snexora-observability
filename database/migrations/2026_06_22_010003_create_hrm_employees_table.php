<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            // Identity
            $table->string('emp_no', 30)->unique();
            $table->string('first_name', 80);
            $table->string('middle_name', 80)->nullable();
            $table->string('last_name', 80);
            $table->string('full_name', 200); // derived from first/middle/last, stored for fast search/display
            $table->string('gender', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('national_id', 30)->nullable();

            // Statutory identifiers (Kenya)
            $table->string('kra_pin', 20)->nullable();
            $table->string('nssf_no', 30)->nullable();
            $table->string('shif_no', 30)->nullable();
            $table->string('nita_no', 30)->nullable();
            $table->string('helb_no', 30)->nullable();

            // Contact
            $table->string('email', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('physical_address', 255)->nullable();

            // Organisation
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('job_title_id')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable(); // self-referencing FK -> employees.id
            $table->string('user_id', 60)->nullable(); // optional login name (users.user_id is a string, not an integer PK — see CLAUDE.md)

            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $table->foreign('job_title_id')->references('id')->on('job_titles')->nullOnDelete();
            // Self-referencing FK: SQL Server rejects ON DELETE SET NULL/CASCADE here
            // ("may cause cycles or multiple cascade paths") — no action is required.
            $table->foreign('manager_id')->references('id')->on('employees');

            // Employment
            $table->enum('employment_type', ['permanent', 'contract', 'casual', 'intern'])->default('permanent');
            $table->date('hire_date')->nullable();
            $table->date('end_date')->nullable();
            $table->enum('status', ['active', 'on_leave', 'suspended', 'terminated', 'inactive'])->default('active');

            // Compensation
            $table->decimal('basic_salary', 14, 2)->default(0);
            $table->enum('payment_method', ['bank', 'mpesa', 'cash'])->default('bank');
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_branch', 100)->nullable();
            $table->string('bank_account', 50)->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
