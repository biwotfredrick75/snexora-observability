<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hrm_leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hrm_employees')->noActionOnDelete();
            $table->foreignId('leave_type_id')->constrained('hrm_leave_types')->noActionOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('entitled_days', 6, 1)->default(0);
            $table->decimal('carried_forward_days', 6, 1)->default(0);
            $table->decimal('taken_days', 6, 1)->default(0);
            $table->decimal('pending_days', 6, 1)->default(0);
            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_leave_balances');
    }
};
