<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_references', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('trans_type', 60)->unique();   // machine key: 'sales_invoice'
            $table->string('trans_name', 100);            // display: 'Sales Invoice'
            $table->string('prefix', 20)->default('');    // e.g. 'JNL', 'INV', 'PO'
            $table->string('pattern', 100)->default('{001}/{YYYY}');
            $table->boolean('is_default')->default(true);
            $table->string('memo', 255)->default('');
            $table->boolean('inactive')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_references');
    }
};
