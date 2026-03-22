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
        Schema::create('gld_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('trans_no');          // source document ID
            $table->smallInteger('type');                 // same type codes as stock_movements
            $table->date('tran_date');
            $table->string('account_code', 15);           // GL account debited or credited
            $table->string('reference', 60)->default('');
            $table->text('narration')->nullable();
            $table->decimal('amount', 18, 4);             // positive = debit, negative = credit
            $table->string('created_by', 100)->default('');
            $table->unsignedInteger('dimension_id')->nullable();
            $table->unsignedInteger('dimension2_id')->nullable();
            $table->timestamps();

            $table->index(['type', 'trans_no']);
            $table->index(['account_code', 'tran_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gld_transactions');
    }
};
