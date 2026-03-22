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
        Schema::create('packaging_receives', function (Blueprint $table) {
            $table->id();
            $table->date('receiving_date');
            $table->string('customer_name');
            $table->string('branch')->nullable();
            $table->string('return_to_location_id')->nullable();
            $table->text('comments')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('packaging_receive_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('packaging_receive_id')->constrained()->cascadeOnDelete();
            $table->foreignId('packaging_type_id')->constrained();
            $table->decimal('quantity', 14, 2)->default(0);
            $table->string('condition')->default('good');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packaging_receive_items');
        Schema::dropIfExists('packaging_receives');
    }
};
