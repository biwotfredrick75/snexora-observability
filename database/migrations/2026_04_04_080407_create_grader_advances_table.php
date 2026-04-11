<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grader_advances', function (Blueprint $table) {
            $table->id();
            // Grader = inventory_location (collection station)
            $table->unsignedBigInteger('grader_id');
            $table->date('advance_date');
            $table->decimal('amount', 15, 4);
            $table->string('reference', 60)->nullable();
            $table->text('notes')->nullable();
            $table->string('created_by', 100)->nullable();
            $table->timestamps();

            $table->index(['grader_id', 'advance_date']);
            $table->foreign('grader_id')->references('id')->on('inventory_locations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grader_advances');
    }
};
