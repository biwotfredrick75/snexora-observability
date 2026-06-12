<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hrm_leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->decimal('days_per_year', 5, 1)->default(0);
            $table->boolean('is_paid')->default(true);
            $table->boolean('requires_approval')->default(true);
            $table->boolean('allow_carry_forward')->default(false);
            $table->decimal('max_carry_forward_days', 5, 1)->default(0);
            $table->string('color', 20)->default('#2563eb');
            $table->boolean('inactive')->default(false);
            $table->timestamps();

            $table->index('inactive');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_leave_types');
    }
};
