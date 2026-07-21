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
        Schema::create('milk_transfer_receptions', function (Blueprint $table) {
            $table->id();
            $table->string('trip_ref', 30)->index();
            $table->unsignedBigInteger('to_location_id');
            $table->unsignedBigInteger('transporter_id')->nullable();

            // Sum of the trip's dispatched legs at the moment of reception.
            $table->decimal('dispatched_qty', 12, 3)->default(0);

            // Reception-side weighing.
            $table->decimal('received_gross_weight', 12, 3)->nullable();
            $table->decimal('received_tare_weight', 12, 3)->nullable();
            $table->decimal('received_net_weight', 12, 3)->nullable();

            // Cold-chain compliance record.
            $table->decimal('temperature_c', 5, 2)->nullable();

            // Quality retest — same shape as milk_purchase_items.
            $table->string('smell_result', 20)->nullable();
            $table->string('alcohol_test_result', 20)->nullable();
            $table->decimal('density', 6, 4)->nullable();
            $table->decimal('butterfat_percent', 5, 2)->nullable();
            $table->string('adulteration_result', 20)->nullable();
            $table->string('quality_status', 20)->default('accepted');
            $table->string('rejection_reason', 500)->nullable();

            // Reconciliation results.
            $table->decimal('received_qty', 12, 3)->default(0);
            $table->decimal('accepted_qty', 12, 3)->default(0);
            $table->decimal('rejected_qty', 12, 3)->default(0);
            $table->decimal('shortage_qty', 12, 3)->default(0);

            // Transporter charge for shortage + rejected value (see grader_deductions).
            $table->decimal('deduction_amount', 15, 2)->default(0);

            $table->string('received_by', 100)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->foreign('to_location_id')->references('id')->on('inventory_locations')->noActionOnDelete();
            $table->foreign('transporter_id')->references('id')->on('inventory_locations')->noActionOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milk_transfer_receptions');
    }
};
