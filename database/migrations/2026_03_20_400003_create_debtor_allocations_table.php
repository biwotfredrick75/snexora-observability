<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debtor_allocations', function (Blueprint $table) {
            $table->id();
            $table->string('debtor_no', 10);
            $table->foreign('debtor_no')->references('debtor_no')->on('customers');
            $table->enum('source_type', ['credit_note'])->default('credit_note');
            $table->unsignedBigInteger('source_id');  // no DB FK — enforced at service layer
            $table->string('source_no', 25);          // CN number, denormalised for display
            $table->unsignedBigInteger('inv_id');
            $table->foreign('inv_id')->references('id')->on('sales_invoices');
            $table->string('inv_no', 25);             // invoice number, denormalised
            $table->decimal('amount', 15, 2);
            $table->date('allocated_date');
            $table->string('created_by', 50)->nullable();
            $table->timestamps();

            $table->index('inv_id');
            $table->index(['source_type', 'source_id']);
            $table->index('debtor_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debtor_allocations');
    }
};
