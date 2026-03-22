<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gld_transactions', function (Blueprint $table) {
            $table->increments('counter');
            $table->smallInteger('type')->default(0);
            $table->integer('type_no')->default(0);
            $table->date('tran_date')->nullable();
            $table->string('account', 15)->default('');
            $table->tinyText('memo_')->nullable();
            $table->double('amount')->default(0);
            $table->integer('dimension_id')->default(0);
            $table->integer('dimension2_id')->default(0);
            $table->integer('person_type_id')->nullable();
            $table->string('person_id', 200)->nullable();
            $table->string('tid', 200)->nullable();
            $table->double('original_amt')->default(0);
            $table->smallInteger('old_sytem')->default(0);
            $table->double('old_sytem_value')->default(0);
            $table->integer('trans_id')->default(0);
            $table->string('vehicle_payment',100)->nullable();
            $table->string('supplier_id',200)->nullable();
            $table->string('unique_key',200)->nullable();
            $table->index(['type','type_no'],'Type_and_Number');
            $table->index('dimension_id');
            $table->index('dimension2_id');
            $table->index('tran_date');
            $table->index(['account','tran_date'],'account_and_tran_date');
            $table->index('tid','gl_tid_idx');
            $table->index('unique_key','idx_unique_key');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('gld_transactions');
    }
};
