<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milk_supp_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('trans_no')->default(0);
            $table->string('unique_key', 60)->nullable();
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('grn_batch_id');
            $table->unsignedInteger('supplier_id')->nullable();
            $table->string('reference', 100)->nullable();
            $table->date('tran_date');
            $table->date('due_date')->nullable();
            $table->decimal('amount', 14, 4)->default(0);
            $table->string('shift', 50)->default('');
            $table->timestamps();

            $table->index('trans_no');
            $table->foreign('purchase_id')->references('id')->on('milk_purchases')->cascadeOnDelete();
            $table->foreign('grn_batch_id')->references('id')->on('milk_grn_batches')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milk_supp_invoices');
    }
};
