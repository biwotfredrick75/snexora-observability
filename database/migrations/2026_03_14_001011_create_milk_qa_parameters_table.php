<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milk_qa_parameters', function (Blueprint $table) {
            $table->id();
            $table->string('parameter_name');
            $table->enum('data_type', ['number', 'text', 'test'])->default('number');
            $table->integer('display_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milk_qa_parameters');
    }
};
