<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milk_prices', function (Blueprint $table) {
            $table->id();
            $table->enum('price_type', ['normal', 'special', 'monthly'])->default('normal');
            $table->decimal('from_qty', 10, 2)->default(0);
            $table->decimal('to_qty', 10, 2)->default(0);
            $table->decimal('price', 10, 4);
            $table->date('date_from');
            $table->date('date_to');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milk_prices');
    }
};
