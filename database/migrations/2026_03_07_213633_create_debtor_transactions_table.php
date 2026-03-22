<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debtor_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('transaction_number')->default(0);
            $table->unsignedSmallInteger('transaction_type')->default(0);
            $table->unsignedTinyInteger('version')->default(0);
            $table->integer('debtor_id')->default(0);
            $table->integer('customer_id')->default(0);
            $table->integer('customer_branch_id')->default(-1);
            $table->date('transaction_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('transaction_reference', 60)->default('');
            $table->integer('transaction_category')->default(0);
            $table->integer('sales_order_number')->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('freight_amount', 18, 2)->default(0);
            $table->decimal('freight_tax', 18, 2)->default(0);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('allocated_amount', 18, 2)->default(0);
            $table->decimal('prepared_amount', 18, 2)->default(0);
            $table->decimal('currency_rate', 18, 6)->default(1);
            $table->integer('shipping_method')->nullable();
            $table->integer('dimension1')->default(0);
            $table->integer('dimension2')->default(0);
            $table->integer('payment_terms')->nullable();
            $table->boolean('is_tax_included')->default(false);
            $table->string('packing_slip', 200)->nullable();
            $table->string('vehicle_number', 50)->nullable();
            $table->string('shift', 50)->nullable();
            $table->string('created_by', 50)->nullable();
            $table->string('receipt_number', 100)->nullable();
            $table->integer('user_id')->default(0);
            $table->integer('print_count')->default(0);
            $table->decimal('previous_reading', 18, 2)->default(0);
            $table->decimal('current_reading', 18, 2)->default(0);
            $table->string('external_invoice_number', 200)->nullable();
            $table->string('control_code', 200)->nullable();
            $table->text('qr_code')->nullable();
            $table->string('qr_code_date', 50)->nullable();
            $table->string('qr_code_response', 100)->nullable();
            $table->smallInteger('is_from_old_system')->default(0);
            $table->decimal('old_system_value', 18, 2)->default(0);
            $table->string('transaction_sub_type', 50)->nullable();
            $table->string('staff_id', 30)->nullable();
            $table->boolean('is_dispatched')->default(false);
            $table->string('dispatched_by', 100)->nullable();
            $table->timestamp('dispatch_time')->nullable();
            $table->string('dispatched_to', 100)->nullable();
            $table->boolean('is_collected')->default(false);
            $table->timestamp('collection_time')->nullable();
            $table->string('collected_by', 100)->nullable();
            $table->string('scu_invoice_number', 100)->nullable();
            $table->string('scu_id', 100)->nullable();
            $table->integer('current_receipt_number')->default(0);
            $table->integer('total_receipt_count')->default(0);
            $table->string('integration_data', 200)->nullable();
            $table->string('receipt_signature', 200)->nullable();
            $table->string('short_url', 200)->nullable();
            $table->string('short_id', 200)->nullable();
            $table->text('long_url')->nullable();
            $table->timestamp('system_date_time')->nullable();
            $table->timestamp('etims_time')->nullable();
            $table->string('etims_user', 30)->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'customer_branch_id']);
            $table->index('transaction_date');
            $table->index('sales_order_number');
            $table->index('transaction_type');
            $table->index('transaction_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debtor_transactions');
    }
};
