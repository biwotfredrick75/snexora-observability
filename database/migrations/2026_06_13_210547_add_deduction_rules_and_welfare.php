<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Earning / deduction type catalogue (the "dynamic setup")
        Schema::create('casual_earning_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->enum('category', ['earning', 'deduction'])->default('deduction');
            $table->enum('frequency', ['daily', 'fixed'])->default('daily');
            // daily  → amount × days_worked per worker
            // fixed  → flat amount posted once per worker per run
            $table->decimal('default_amount', 10, 2)->default(0);
            // welfare_only: skipped for workers in trades where pay_welfare = false
            $table->boolean('welfare_only')->default(false);
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Per-run postings: one row per worker per type per run
        Schema::create('casual_pay_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_run_id')->constrained('casual_worker_pay_runs')->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained('casual_workers')->cascadeOnDelete();
            $table->foreignId('earning_type_id')->constrained('casual_earning_types')->cascadeOnDelete();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->unique(['pay_run_id', 'worker_id', 'earning_type_id']);
        });

        // Welfare flag on trades controls whether welfare-only types apply
        Schema::table('casual_worker_trades', function (Blueprint $table) {
            $table->boolean('pay_welfare')->default(true)->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('casual_pay_postings');
        Schema::dropIfExists('casual_earning_types');
        Schema::table('casual_worker_trades', function (Blueprint $table) {
            $table->dropColumn('pay_welfare');
        });
    }
};
