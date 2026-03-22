<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_deposits', function (Blueprint $table) {
            $table->id();
            $table->string('deposit_no', 25)->unique();           // DEP/001/2026
            $table->string('debtor_no', 10);
            $table->foreign('debtor_no')->references('debtor_no')->on('customers');
            $table->string('bank_account_code', 20)->nullable();  // GL account for the bank
            $table->date('deposit_date');
            $table->decimal('deposit_amount', 15, 2);
            $table->string('reference', 60)->nullable();
            $table->string('external_reference', 60)->nullable();
            $table->string('branch', 100)->nullable();
            $table->text('memo')->nullable();
            $table->enum('status', ['posted', 'cancelled'])->default('posted');
            $table->string('created_by', 50)->nullable();
            $table->timestamps();

            $table->index('debtor_no');
            $table->index('deposit_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_deposits');
    }
};
