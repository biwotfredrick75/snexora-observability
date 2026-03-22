<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('debtor_trans_details', function (Blueprint $table) {

            $table->increments('id');
            $table->integer('debtorTransactionNumber')->nullable();
            $table->integer('debtorTransactionType')->nullable();
            $table->string('stockId', 20)->default('');
            $table->tinyText('description')->nullable();
            $table->decimal('unitPrice', 18,2)->default(0);
            $table->decimal('unitTax', 18,2)->default(0);
            $table->decimal('quantity', 18,2)->default(0);
            $table->decimal('discountPercent', 5,2)->default(0);
            $table->decimal('standardCost', 18,2)->default(0);
            $table->decimal('quantityDone', 18,2)->default(0);
            $table->integer('sourceId')->default(0);
            $table->decimal('quantityClaimed', 18,2)->default(0);
            $table->integer('quantityReturn')->default(0);
            $table->decimal('quantityDelivered', 18,2)->default(0);
            $table->string('subUnit', 250)->nullable();
            $table->json('inspections')->nullable();
            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */
            $table->index(['debtorTransactionType','debtorTransactionNumber'], 'transaction_idx');
            $table->index('sourceId', 'source_id_idx');
            $table->index('debtorTransactionNumber', 'idxDebtorTransDetailsTransactionNumber');
            $table->index('debtorTransactionType', 'idxDebtorTransDetailsTransactionType');
            $table->index('stockId', 'idxDebtorTransDetailsStockId');
            /*
            |--------------------------------------------------------------------------
            | Foreign Key
            |--------------------------------------------------------------------------
            */
            // FK removed: debtorTransactions table uses different schema
        });
    }

    public function down()
    {
        Schema::dropIfExists('debtor_trans_details');
    }
}
