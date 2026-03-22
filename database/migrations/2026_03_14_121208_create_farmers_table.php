<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('farmers', function (Blueprint $table) {
            $table->id();
            $table->string('farmer_no')->unique();
            $table->string('full_name');
            $table->string('short_name')->nullable();
            $table->text('address')->nullable();
            $table->string('kra_pin')->nullable();
            $table->string('id_no')->nullable();
            $table->string('currency', 10)->default('KES');
            $table->unsignedBigInteger('payment_terms_id')->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('bank_id')->nullable();
            $table->string('account_no')->nullable();
            $table->string('gender', 10)->nullable();
            $table->date('dob')->nullable();
            $table->unsignedBigInteger('route_id')->nullable();
            $table->string('village')->nullable();
            $table->timestamps();

            $table->foreign('bank_id')->references('id')->on('farmer_banks')->nullOnDelete();
            $table->foreign('route_id')->references('id')->on('milk_routes')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('farmers');
    }
};
