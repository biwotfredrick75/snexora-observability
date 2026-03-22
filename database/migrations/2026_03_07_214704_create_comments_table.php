<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->integer('transactionType')->default(0); // type
            $table->integer('transactionId')->default(0);   // id
            $table->date('transactionDate')->nullable();     // date_
            $table->text('memo')->nullable();               // memo_
            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */
            $table->index(['transactionType','transactionId'], 'type_and_id_idx');
        });
    }
    public function down()
    {
        Schema::dropIfExists('comments');
    }
}
