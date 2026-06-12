<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hrm_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no', 30)->unique();
            $table->foreignId('employee_id')->constrained('hrm_employees')->noActionOnDelete();
            $table->foreignId('leave_type_id')->constrained('hrm_leave_types')->noActionOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('days', 5, 1)->default(0); // working days, weekends excluded
            $table->text('reason')->nullable();
            // pending | approved | rejected | cancelled
            $table->string('status', 20)->default('pending');
            $table->dateTime('applied_at')->nullable();
            $table->string('approved_by', 60)->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_leave_requests');
    }
};
