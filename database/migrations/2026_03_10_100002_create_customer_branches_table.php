<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_branches', function (Blueprint $table) {
            $table->id();
            $table->string('debtor_no', 10);
            $table->foreign('debtor_no')->references('debtor_no')->on('customers')->noActionOnDelete();
            $table->string('branch_name', 100);
            $table->string('phone', 30)->nullable();
            $table->string('deliver_to', 100)->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_branches');
    }
};
