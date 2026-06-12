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
        Schema::create('packaging_transfers', function (Blueprint $table) {
            $table->id();
            $table->date('transfer_date');
            $table->string('from_location_id');
            $table->string('to_location_id');
            $table->text('comments')->nullable();
            $table->enum('status', ['draft', 'processed'])->default('draft');
            $table->string('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('packaging_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('packaging_transfer_id')->constrained()->noActionOnDelete();
            $table->foreignId('packaging_type_id')->constrained();
            $table->decimal('qty_good', 14, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packaging_transfer_items');
        Schema::dropIfExists('packaging_transfers');
    }
};
