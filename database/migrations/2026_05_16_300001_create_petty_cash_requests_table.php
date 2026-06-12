<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no', 30)->unique();
            $table->string('requested_by', 60);
            $table->date('request_date');
            $table->string('purpose', 200);
            $table->text('description')->nullable();
            $table->decimal('amount_requested', 15, 2);
            $table->string('expense_account_code', 20);
            $table->string('petty_cash_account_code', 20);
            // pending | approved | rejected | disbursed | retired
            $table->string('status', 20)->default('pending');
            $table->string('approved_by', 60)->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            $table->string('disbursed_by', 60)->nullable();
            $table->dateTime('disbursed_at')->nullable();
            $table->decimal('amount_disbursed', 15, 2)->nullable();
            $table->string('retired_by', 60)->nullable();
            $table->dateTime('retired_at')->nullable();
            $table->decimal('actual_amount_spent', 15, 2)->nullable();
            $table->decimal('amount_returned', 15, 2)->nullable();
            $table->string('receipt_reference', 100)->nullable();
            $table->text('retirement_notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('request_date');
            $table->index('requested_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_requests');
    }
};
