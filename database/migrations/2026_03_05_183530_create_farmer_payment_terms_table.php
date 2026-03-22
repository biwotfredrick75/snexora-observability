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
        Schema::create('farmer_payment_terms', function (Blueprint $table) {
            $table->id();
            $table->string('description', 100);
            $table->enum('type', ['prepayment', 'after_days', 'cash', 'end_of_month'])->default('after_days');
            $table->integer('due_after_days')->nullable();
            $table->string('shift', 20)->default('Both');
            $table->boolean('inactive')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('farmer_payment_terms');
    }
};
