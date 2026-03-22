<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('milk_location_transfers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('from_location_id')->nullable();
            $table->unsignedBigInteger('to_location_id')->nullable();
            $table->date('transfer_date');
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->decimal('quantity', 12, 3)->default(0);
            $table->text('quality_notes')->nullable();
            $table->string('status', 30)->default('processed');
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->foreign('from_location_id')->references('id')->on('inventory_locations')->nullOnDelete();
            $table->foreign('to_location_id')->references('id')->on('inventory_locations')->nullOnDelete();
            $table->foreign('shift_id')->references('id')->on('milk_collection_shifts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milk_location_transfers');
    }
};
