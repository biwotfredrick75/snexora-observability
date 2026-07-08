<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedTinyInteger('progress')->default(0); // 0-100
            $table->string('status', 20)->default('active'); // active|completed|cancelled
            $table->timestamps();
        });

        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('period', 40); // e.g. "Q2 2026"
            $table->unsignedTinyInteger('rating')->nullable(); // 1-5
            $table->text('comments')->nullable();
            $table->string('status', 20)->default('draft'); // draft|submitted|acknowledged
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_reviews');
        Schema::dropIfExists('performance_goals');
    }
};
