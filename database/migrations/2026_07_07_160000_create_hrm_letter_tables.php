<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_letter_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('category', 40)->default('other'); // offer|confirmation|experience|certificate|warning|other
            $table->text('body'); // supports {{placeholder}} tokens, merged against employee data
            $table->boolean('inactive')->default(false);
            $table->timestamps();
        });

        Schema::create('hr_letters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('hr_letter_templates')->nullOnDelete();
            $table->string('title', 150);
            $table->text('body'); // the rendered/merged letter text at time of issue
            $table->foreignId('issued_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_letters');
        Schema::dropIfExists('hr_letter_templates');
    }
};
