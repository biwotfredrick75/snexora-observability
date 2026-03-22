<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDebtorTransactionsTable extends Migration
{
    public function up()
    {
        Schema::create('debtor_transactions', function (Blueprint $table) {
            $table->unsignedInteger('transactionNumber')->default(0);
            $table->unsignedSmallInteger('transactionType')->default(0);
            $table->unsignedTinyInteger('version')->default(0);
            $table->integer('debtorId');
            $table->integer('customerBranchId')->default(-1);
            $table->date('transactionDate')->nullable();
            $table->date('dueDate')->nullable();
            $table->string('transactionReference', 60)->default('');
            $table->integer('transactionCategory')->default(0); 
            $table->integer('salesOrderNumber')->default(0);
            $table->decimal('totalAmount', 18,2)->default(0);
            $table->decimal('taxAmount', 18,2)->default(0);
            $table->decimal('freightAmount', 18,2)->default(0);
            $table->decimal('freightTax', 18,2)->default(0);
            $table->decimal('discountAmount', 18,2)->default(0);
            $table->decimal('allocatedAmount', 18,2)->default(0);
            $table->decimal('preparedAmount', 18,2)->default(0);
            $table->decimal('currencyRate', 18,6)->default(1);
            $table->integer('shippingMethod')->nullable();
            $table->integer('dimension1')->default(0);
            $table->integer('dimension2')->default(0);
            $table->integer('paymentTerms')->nullable();
            $table->boolean('isTaxIncluded')->default(false);
            $table->string('packingSlip', 200);
            $table->string('vehicleNumber',50);
            $table->string('shift',50);
            $table->string('createdBy',50);
            $table->string('receiptNumber',100);
            $table->integer('userId');
            $table->integer('printCount')->default(0);
            $table->decimal('previousReading',18,2)->default(0);
            $table->decimal('currentReading',18,2)->default(0);
            $table->string('externalInvoiceNumber',200);
            $table->string('controlCode',200);
            $table->text('qrCode');
            $table->string('qrCodeDate',50);
            $table->string('qrCodeResponse',100);
            $table->smallInteger('isFromOldSystem')->default(0);
            $table->decimal('oldSystemValue',18,2);
            $table->string('transactionSubType',50);
            $table->string('staffId',30);
            $table->boolean('isDispatched')->default(false);
            $table->string('dispatchedBy',100);
            $table->timestamp('dispatchTime')->useCurrent()->useCurrentOnUpdate();
            $table->string('dispatchedTo',100);
            $table->boolean('isCollected')->default(false);
            $table->timestamp('collectionTime')->nullable();
            $table->string('collectedBy',100);
            $table->string('scuInvoiceNumber',100);
            $table->string('scuId',100);
            $table->integer('currentReceiptNumber');
            $table->integer('totalReceiptCount');
            $table->string('integrationData',200);
            $table->string('receiptSignature',200);
            $table->string('shortUrl',200);
            $table->string('shortId',200);
            $table->text('longUrl');
            $table->timestamp('systemDateTime')->useCurrent()->useCurrentOnUpdate();
            $table->timestamp('etimsTime')->nullable();
            $table->string('etimsUser',30);
            /*
            |--------------------------------------------------------------------------
            | Primary Key
            |--------------------------------------------------------------------------
            */
            $table->primary(['transactionType','transactionNumber','customerId']);
            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */
            $table->index(['customerId','customerBranchId']);
            $table->index('transactionDate');
            $table->index('salesOrderNumber');
            $table->index('customerId','idxCustomerTransactions');
            $table->index('transactionType','idxTransactionType');
            $table->index('transactionDate','idxTransactionDate');
            $table->index('transactionNumber','idxTransactionNumber');
            $table->index(['customerBranchId','customerId'],'idxBranchCustomer');
            $table->index('transactionSubType');
            /*
            |--------------------------------------------------------------------------
            | Foreign Key
            |--------------------------------------------------------------------------
            */
            $table->foreign(['customerBranchId','customerId'])
                ->references(['branch_code','debtor_no'])
                ->on('0_cust_branch')
                ->onDelete('cascade');

        });
    }

    public function down()
    {
        Schema::dropIfExists('debtor_transactions');
    }
}