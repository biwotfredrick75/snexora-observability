<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journals', function (Blueprint $table) {
            $table->smallInteger('type')->default(0);
            $table->integer('trans_no')->default(0);
            $table->date('tran_date')->nullable();
            $table->string('reference', 60)->default('');
            $table->string('source_ref', 60)->default('');
            $table->date('event_date')->nullable();
            $table->date('doc_date')->nullable();
            $table->char('currency', 3)->default('');
            $table->double('amount')->default(0);
            $table->double('rate')->default(1);
            $table->string('tid', 100);
            $table->primary(['type','trans_no']);
            $table->index('tran_date');

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journals');
    }
};