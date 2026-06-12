<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hrm_departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            // Loose pointer to an employee — no FK to avoid create-order coupling
            $table->unsignedBigInteger('head_employee_id')->nullable();
            $table->boolean('inactive')->default(false);
            $table->timestamps();

            $table->index('inactive');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_departments');
    }
};
