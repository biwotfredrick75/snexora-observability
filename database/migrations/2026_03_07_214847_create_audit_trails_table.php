<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('audit_trails', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedSmallInteger('transactionType')->default(0); // type
            $table->unsignedInteger('transactionNumber')->default(0);    // trans_no
            $table->unsignedSmallInteger('userId')->default(0);          // user
            $table->timestamp('stamp')->useCurrent(); // stamp
            $table->string('description', 255)->nullable();                // description
            $table->integer('fiscalYear')->default(0);                     // fiscal_year
            $table->date('glDate')->nullable();                           // gl_date
            $table->unsignedInteger('glSequence')->nullable();             // gl_seq
            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */
            $table->index(['fiscalYear','glDate','glSequence'], 'seq_idx');
            $table->index(['transactionType','transactionNumber'], 'type_and_number_idx');

        });
    }
    public function down()
    {
        Schema::dropIfExists('audit_trails');
    }
}
