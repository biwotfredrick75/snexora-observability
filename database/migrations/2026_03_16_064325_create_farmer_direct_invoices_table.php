<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farmer_direct_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seq')->nullable()->unique();
            $table->string('inv_no', 30)->nullable()->unique();
            $table->unsignedBigInteger('farmer_id')->nullable()->index();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('payment_terms')->nullable();
            $table->boolean('no_loading_order')->default(false);
            $table->decimal('shipping_charge', 12, 4)->default(0);
            $table->decimal('sub_total', 14, 4)->default(0);
            $table->decimal('amount_total', 14, 4)->default(0);
            $table->string('vehicle', 60)->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->string('deliver_to', 150)->nullable();
            $table->text('address')->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('customer_ref', 60)->nullable();
            $table->text('comments')->nullable();
            $table->unsignedBigInteger('shipping_company_id')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('created_by', 60)->nullable();
            $table->timestamps();

            $table->foreign('farmer_id')->references('id')->on('farmers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farmer_direct_invoices');
    }
};
