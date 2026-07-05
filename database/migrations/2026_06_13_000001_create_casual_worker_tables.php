<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('casual_worker_trades', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->decimal('default_daily_rate', 10, 2)->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('casual_workers', function (Blueprint $table) {
            $table->id();
            $table->string('worker_no', 20)->unique();
            $table->string('name', 150);
            $table->string('national_id', 20)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('emergency_contact', 150)->nullable();
            $table->string('emergency_phone', 20)->nullable();
            $table->foreignId('trade_id')->nullable()->constrained('casual_worker_trades')->nullOnDelete();
            $table->string('bank_name', 100)->nullable();
            $table->string('account_no', 50)->nullable();
            $table->string('mpesa_no', 20)->nullable();
            $table->enum('status', ['active', 'inactive', 'blacklisted'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('casual_worker_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('casual_workers')->cascadeOnDelete();
            $table->date('work_date');
            $table->enum('shift', ['full_day', 'morning', 'afternoon', 'night'])->default('full_day');
            $table->enum('status', ['present', 'absent', 'late', 'half_day'])->default('present');
            $table->decimal('hours_worked', 5, 2)->default(8);
            $table->string('check_in', 10)->nullable();
            $table->string('check_out', 10)->nullable();
            $table->string('notes', 255)->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();
            $table->unique(['worker_id', 'work_date', 'shift']);
        });

        Schema::create('casual_worker_pay_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->nullable()->constrained('casual_workers')->cascadeOnDelete();
            $table->foreignId('trade_id')->nullable()->constrained('casual_worker_trades')->nullOnDelete();
            $table->enum('rate_type', ['daily', 'hourly'])->default('daily');
            $table->decimal('rate', 10, 2)->default(0);
            $table->decimal('overtime_multiplier', 4, 2)->default(1.5);
            $table->decimal('transport_allowance', 10, 2)->default(0);
            $table->decimal('meal_allowance', 10, 2)->default(0);
            $table->date('effective_from');
            $table->timestamps();
        });

        Schema::create('casual_worker_pay_runs', function (Blueprint $table) {
            $table->id();
            $table->string('ref_no', 50)->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('status', ['draft', 'generated', 'approved', 'paid'])->default('draft');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('casual_worker_pay_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pay_run_id')->constrained('casual_worker_pay_runs')->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained('casual_workers')->cascadeOnDelete();
            $table->decimal('days_worked', 5, 2)->default(0);
            $table->decimal('hours_worked', 7, 2)->default(0);
            $table->decimal('base_rate', 10, 2)->default(0);
            $table->decimal('base_pay', 15, 2)->default(0);
            $table->decimal('transport_allowance', 15, 2)->default(0);
            $table->decimal('meal_allowance', 15, 2)->default(0);
            $table->decimal('other_allowances', 15, 2)->default(0);
            $table->decimal('deductions', 15, 2)->default(0);
            $table->decimal('net_pay', 15, 2)->default(0);
            $table->enum('payment_status', ['pending', 'paid'])->default('pending');
            $table->string('payment_ref', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('casual_worker_pay_run_items');
        Schema::dropIfExists('casual_worker_pay_runs');
        Schema::dropIfExists('casual_worker_pay_rates');
        Schema::dropIfExists('casual_worker_attendances');
        Schema::dropIfExists('casual_workers');
        Schema::dropIfExists('casual_worker_trades');
    }
};
