<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grader_collection_rates', function (Blueprint $table) {
            $table->id();
            // Destination location where milk is transferred to (milk_routes.location_id)
            $table->unsignedBigInteger('location_id');
            $table->decimal('rate', 12, 4);           // rate per litre for grader collection
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('inactive')->default(false);
            $table->string('notes', 255)->nullable();
            $table->string('created_by', 100)->nullable();
            $table->timestamps();

            $table->index(['location_id', 'inactive']);
            $table->foreign('location_id')->references('id')->on('inventory_locations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grader_collection_rates');
    }
};
