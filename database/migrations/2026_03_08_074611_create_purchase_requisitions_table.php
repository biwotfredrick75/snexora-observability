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
        Schema::create('purchase_requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('pr_no', 30)->unique();
            $table->string('reference', 60)->default('');
            $table->string('branch', 60)->default('');          // location/branch name
            $table->date('requisition_date');
            $table->date('due_date')->nullable();
            $table->string('status', 30)->default('draft');     // draft|submitted|hod_approved|finance_approved|ceo_approved|rejected
            $table->string('raised_by', 60)->default('');
            $table->string('hod_approval_by', 60)->nullable();
            $table->string('finance_approval_by', 60)->nullable();
            $table->string('ceo_approval_by', 60)->nullable();
            $table->date('hod_approval_date')->nullable();
            $table->date('finance_approval_date')->nullable();
            $table->date('ceo_approval_date')->nullable();
            $table->decimal('amount', 18, 4)->default(0);
            $table->text('comments')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_requisition_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pr_id');
            $table->string('stock_id', 20)->default('');
            $table->string('description', 200)->default('');
            $table->decimal('qty', 14, 4)->default(0);
            $table->string('unit_of_measure', 30)->default('');
            $table->decimal('line_total', 18, 4)->default(0);
            $table->foreign('pr_id')->references('id')->on('purchase_requisitions')->noActionOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_requisition_items');
        Schema::dropIfExists('purchase_requisitions');
    }
};
