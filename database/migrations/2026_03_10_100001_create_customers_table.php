<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->string('debtor_no', 10)->primary();
            $table->string('name', 100);
            $table->string('short_name', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 100)->nullable();
            $table->integer('payment_terms')->nullable();
            $table->integer('price_list_id')->nullable();
            $table->decimal('discount', 5, 2)->default(0);
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->decimal('current_credit', 15, 2)->default(0);
            $table->boolean('inactive')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
