<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_budgets', function (Blueprint $table) {
            $table->id();
            $table->string('account_code', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month'); // 1-12
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('created_by', 60)->nullable();
            $table->timestamps();

            $table->unique(['account_code', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_budgets');
    }
};
