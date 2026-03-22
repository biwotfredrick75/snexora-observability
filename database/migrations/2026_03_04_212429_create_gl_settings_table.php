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
        Schema::create('gl_settings', function (Blueprint $table) {
            $table->id();

            // General GL
            $table->unsignedInteger('past_due_days_interval')->default(0);
            $table->string('accounts_type', 20)->default('numeric');
            $table->string('retained_earnings_account', 20)->nullable();
            $table->string('profit_loss_year_account', 20)->nullable();
            $table->string('exchange_variances_account', 20)->nullable();
            $table->string('bank_charges_account', 20)->nullable();
            $table->string('tax_deduction_account', 20)->nullable();
            $table->string('tax_algorithm', 30)->default('taxes_from_totals');

            // Dimension Defaults
            $table->unsignedInteger('dimension_required_after')->default(20);

            // Customers and Sales
            $table->decimal('default_credit_limit', 15, 2)->default(1000.00);
            $table->decimal('default_credit_invoices', 15, 2)->default(1.00);
            $table->string('invoice_identification', 20)->default('reference');
            $table->boolean('accumulate_batch_shipping')->default(false);
            $table->boolean('print_item_image_on_quote')->default(false);
            $table->text('legal_text_on_invoice')->nullable();
            $table->string('shipping_charged_account', 20)->nullable();
            $table->string('deferred_income_account', 20)->nullable();

            // Customers and Sales Defaults
            $table->string('receivable_account', 20)->nullable();
            $table->string('sales_account', 20)->nullable();
            $table->string('sales_discount_account', 20)->nullable();
            $table->string('prompt_payment_discount_account', 20)->nullable();
            $table->unsignedInteger('quote_valid_days')->default(30);
            $table->unsignedInteger('delivery_required_by')->default(1);

            // Suppliers and Purchasing
            $table->decimal('delivery_over_receive_allowance', 5, 1)->default(10.0);
            $table->decimal('invoice_over_charge_allowance', 5, 1)->default(10.0);

            // Suppliers and Purchasing Defaults
            $table->string('payable_account', 20)->nullable();
            $table->string('purchase_discount_account', 20)->nullable();
            $table->string('grn_clearing_account', 20)->nullable();
            $table->unsignedInteger('receival_required_by')->default(10);
            $table->boolean('show_po_item_codes')->default(false);

            // Inventory
            $table->boolean('allow_negative_inventory')->default(false);
            $table->boolean('no_zero_amounts_service')->default(false);
            $table->boolean('location_notifications')->default(false);
            $table->boolean('allow_negative_prices')->default(false);

            // Items Defaults
            $table->string('items_sales_account', 20)->nullable();
            $table->string('items_inventory_account', 20)->nullable();
            $table->string('items_cogs_account', 20)->nullable();
            $table->string('items_inventory_adjustments_account', 20)->nullable();
            $table->string('items_wip_account', 20)->nullable();

            // Fixed Assets Defaults
            $table->string('loss_on_asset_disposal_account', 20)->nullable();
            $table->string('depreciation_period', 20)->default('yearly');

            // Manufacturing Defaults
            $table->unsignedInteger('work_order_required_by_after')->default(20);
            $table->string('vat_device_url', 255)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gl_settings');
    }
};
