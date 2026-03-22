<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('milk_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('tid', 50)->unique();        // e.g. TIN-00000001
            $table->date('date_of_trans');
            $table->tinyInteger('status')->default(1);
            $table->string('dateandtime', 30)->nullable();
            $table->timestamps();

            $table->index('tid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milk_transactions');
    }
};
