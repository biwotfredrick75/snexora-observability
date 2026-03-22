<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milk_prices_per_member', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_id');
            $table->string('month');
            $table->integer('year');
            $table->date('begin_date');
            $table->date('end_date');
            $table->decimal('price', 10, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milk_prices_per_member');
    }
};
