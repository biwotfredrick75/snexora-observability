<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_journal_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('journal_id');
            $table->string('transaction_type', 30);   // 'Supplier Invoice' | 'GRN Receipt'
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedInteger('supplier_id');
            $table->decimal('amount', 15, 4);
            $table->string('created_by', 60)->nullable();
            $table->timestamps();

            $table->foreign('journal_id')->references('id')->on('journal_entries')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_journal_allocations');
    }
};
