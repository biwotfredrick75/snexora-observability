<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared checklist engine for both Onboarding and Offboarding — same shape
 * of workflow (a set of tasks to tick off for one employee), distinguished
 * only by `type`. Avoids maintaining two near-identical table/controller
 * pairs for what is functionally the same feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20); // onboarding|offboarding
            $table->string('title', 150);
            $table->string('description', 255)->nullable();
            $table->string('category', 60)->nullable(); // e.g. IT, HR, Admin, Finance
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('inactive')->default(false);
            $table->timestamps();
        });

        Schema::create('hr_checklist_processes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('type', 20); // onboarding|offboarding
            $table->string('status', 20)->default('in_progress'); // in_progress|completed
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_checklist_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('process_id')->constrained('hr_checklist_processes')->cascadeOnDelete();
            $table->string('title', 150);
            $table->string('description', 255)->nullable();
            $table->string('category', 60)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_done')->default(false);
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_checklist_tasks');
        Schema::dropIfExists('hr_checklist_processes');
        Schema::dropIfExists('hr_checklist_items');
    }
};
