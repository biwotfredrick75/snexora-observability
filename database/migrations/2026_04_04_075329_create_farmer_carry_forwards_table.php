<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farmer_carry_forwards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('farmer_id');
            $table->unsignedTinyInteger('to_month');    // period the deficit is charged against
            $table->unsignedSmallInteger('to_year');
            $table->decimal('amount', 15, 4);           // positive = farmer owes this (deficit carried forward)
            $table->string('notes', 255)->nullable();
            $table->unsignedBigInteger('from_batch_id')->nullable(); // batch that produced this carry-forward
            $table->string('created_by', 100)->nullable();
            $table->timestamps();

            $table->unique(['farmer_id', 'to_month', 'to_year']);
            $table->index(['to_month', 'to_year']);
            $table->foreign('farmer_id')->references('id')->on('farmers')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farmer_carry_forwards');
    }
};
