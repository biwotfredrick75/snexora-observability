<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Charges raised against a grader/transporter (e.g. milk shortage or
 * rejection during a cooling-store transfer) — separate from
 * grader_advances (money lent to the grader) and esp_farmer_sales
 * (third-party agrovet credit), and folded into
 * GraderPayrollController::process()/settle() net-pay the same way those
 * two already are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grader_deductions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('grader_id');
            $table->date('deduction_date');
            $table->decimal('amount', 15, 2);
            $table->string('reason', 255)->nullable();
            $table->string('source_type', 30)->default('milk_transport_loss');
            $table->string('source_ref', 30)->nullable();
            $table->boolean('settled')->default(false);
            $table->date('settled_at')->nullable();
            $table->string('settled_ref', 60)->nullable();
            $table->string('created_by', 100)->nullable();
            $table->timestamps();
            $table->foreign('grader_id')->references('id')->on('inventory_locations')->noActionOnDelete();
            $table->index(['grader_id', 'settled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grader_deductions');
    }
};
