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
        Schema::create('milk_purchases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('route_id')->nullable();
            $table->string('grader')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->string('pricing_type', 30)->default('normal');
            $table->string('reference_no')->nullable();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->decimal('total_qty', 12, 3)->default(0);
            $table->string('status', 30)->default('draft');
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->foreign('route_id')->references('id')->on('milk_routes')->nullOnDelete();
            $table->foreign('shift_id')->references('id')->on('milk_collection_shifts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milk_purchases');
    }
};
