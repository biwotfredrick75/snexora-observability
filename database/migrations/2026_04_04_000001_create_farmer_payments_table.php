<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('farmer_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('farmer_id');
            $table->string('from_account', 60)->nullable();   // company bank account
            $table->date('date_paid');
            $table->string('reference', 60)->nullable();
            $table->string('type', 20)->default('payment');   // advance | payment
            $table->decimal('withholding_tax_rate', 8, 4)->default(0);
            $table->decimal('amount_discount', 15, 2)->default(0);
            $table->decimal('amount_payment', 15, 2)->default(0);
            $table->decimal('bank_charge', 15, 2)->default(0);
            $table->string('cheque_no', 60)->nullable();
            $table->text('memo')->nullable();
            $table->string('created_by', 60)->nullable();
            $table->timestamps();

            $table->index('farmer_id');
            $table->index('type');
            $table->index('date_paid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farmer_payments');
    }
};
