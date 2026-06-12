<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mpesa_payments')) {
            return;
        }

        Schema::create('mpesa_payments', function (Blueprint $table) {
            $table->increments('Auto');
            $table->string('TransactionType', 50)->nullable();
            $table->string('TransID', 50)->nullable()->index();
            $table->string('TransTime', 20)->nullable();
            $table->decimal('TransAmount', 15, 2)->default(0);
            $table->string('BusinessShortCode', 20)->nullable()->index();
            $table->string('BilRef', 100)->nullable();
            $table->string('MSISDN', 20)->nullable()->index();
            $table->string('First_Name', 100)->nullable();
            $table->string('Middle_Name', 100)->nullable();
            $table->string('Last_Name', 100)->nullable();
            $table->decimal('OrgAccountBalance', 15, 2)->default(0);
            $table->decimal('custBalance', 15, 2)->default(0);
            $table->decimal('allocated', 15, 2)->default(0);
            $table->dateTime('trans_date')->nullable()->index();
            $table->string('transfer_user', 100)->nullable()->default('');
            $table->dateTime('transfer_time')->nullable();
            $table->string('debtor_no', 10)->nullable()->index();
            $table->unsignedBigInteger('payment_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mpesa_payments');
    }
};
