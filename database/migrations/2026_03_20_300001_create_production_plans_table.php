<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_plans', function (Blueprint $table) {
            $table->id();
            $table->string('plan_no', 25)->unique();
            $table->string('status', 20)->default('draft'); // draft | submitted | approved | rejected | executed
            $table->string('production_location', 100)->nullable();
            $table->date('plan_date');
            $table->date('target_completion_date')->nullable();
            $table->text('memo')->nullable();
            $table->string('created_by', 100)->nullable();
            $table->string('submitted_by', 100)->nullable();
            $table->string('approved_by', 100)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_plans');
    }
};
