<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GlSetting extends Model
{
    protected $table = 'gl_settings';

    protected $fillable = [
        // General GL
        'past_due_days_interval',
        'accounts_type',
        'retained_earnings_account',
        'profit_loss_year_account',
        'exchange_variances_account',
        'bank_charges_account',
        'tax_deduction_account',
        'tax_algorithm',
        // Dimension Defaults
        'dimension_required_after',
        // Customers and Sales
        'default_credit_limit',
        'default_credit_invoices',
        'invoice_identification',
        'accumulate_batch_shipping',
        'print_item_image_on_quote',
        'legal_text_on_invoice',
        'shipping_charged_account',
        'deferred_income_account',
        // Customers and Sales Defaults
        'receivable_account',
        'sales_account',
        'sales_discount_account',
        'prompt_payment_discount_account',
        'quote_valid_days',
        'delivery_required_by',
        // Suppliers and Purchasing
        'delivery_over_receive_allowance',
        'invoice_over_charge_allowance',
        // Suppliers and Purchasing Defaults
        'payable_account',
        'purchase_discount_account',
        'supplier_advance_account',
        'grn_clearing_account',
        'receival_required_by',
        'show_po_item_codes',
        // Farmers (Dairy) Defaults
        'farmers_payable_account',
        'farmers_bank_account',
        'default_tare_kg',
        // SACCO Defaults
        'sacco_savings_liability_account',
        'sacco_share_capital_account',
        'sacco_loans_receivable_account',
        'sacco_loan_interest_income_account',
        'sacco_cash_account',
        'sacco_savings_interest_rate_pct',
        // Inventory
        'allow_negative_inventory',
        'no_zero_amounts_service',
        'location_notifications',
        'allow_negative_prices',
        // Items Defaults
        'items_sales_account',
        'items_inventory_account',
        'items_cogs_account',
        'items_inventory_adjustments_account',
        'items_wip_account',
        // Fixed Assets Defaults
        'loss_on_asset_disposal_account',
        'depreciation_period',
        // Manufacturing Defaults
        'work_order_required_by_after',
        'vat_device_url',
    ];

    protected function casts(): array
    {
        return [
            'accumulate_batch_shipping'  => 'boolean',
            'print_item_image_on_quote'  => 'boolean',
            'show_po_item_codes'         => 'boolean',
            'allow_negative_inventory'   => 'boolean',
            'no_zero_amounts_service'    => 'boolean',
            'location_notifications'     => 'boolean',
            'allow_negative_prices'      => 'boolean',
            'default_credit_limit'       => 'float',
            'default_credit_invoices'    => 'float',
            'delivery_over_receive_allowance' => 'float',
            'invoice_over_charge_allowance'   => 'float',
            'default_tare_kg'                 => 'float',
            'sacco_savings_interest_rate_pct' => 'float',
        ];
    }
}
