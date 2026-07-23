<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\GlSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlSettingController extends Controller
{
    /**
     * GET /api/setup/gl-settings
     * Returns the single GL settings record (creates default if none exists).
     */
    public function show(): JsonResponse
    {
        $settings = GlSetting::first() ?? new GlSetting();

        return ApiResponse::success($settings->toArray(), 'GL settings retrieved');
    }

    /**
     * POST /api/setup/gl-settings
     * Updates (or creates) GL settings.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // General GL
            'past_due_days_interval'          => 'sometimes|integer|min:0',
            'accounts_type'                   => 'sometimes|string|in:numeric,alphabetical',
            'retained_earnings_account'       => 'sometimes|nullable|string|max:20',
            'profit_loss_year_account'        => 'sometimes|nullable|string|max:20',
            'exchange_variances_account'      => 'sometimes|nullable|string|max:20',
            'bank_charges_account'            => 'sometimes|nullable|string|max:20',
            'tax_deduction_account'           => 'sometimes|nullable|string|max:20',
            'tax_algorithm'                   => 'sometimes|string|in:taxes_from_totals,taxes_on_items',
            // Dimension Defaults
            'dimension_required_after'        => 'sometimes|integer|min:0',
            // Customers and Sales
            'default_credit_limit'            => 'sometimes|numeric|min:0',
            'default_credit_invoices'         => 'sometimes|numeric|min:0',
            'invoice_identification'          => 'sometimes|string|in:reference,customer_ref,order_no',
            'accumulate_batch_shipping'       => 'sometimes|boolean',
            'print_item_image_on_quote'       => 'sometimes|boolean',
            'legal_text_on_invoice'           => 'sometimes|nullable|string',
            'shipping_charged_account'        => 'sometimes|nullable|string|max:20',
            'deferred_income_account'         => 'sometimes|nullable|string|max:20',
            // Customers and Sales Defaults
            'receivable_account'              => 'sometimes|nullable|string|max:20',
            'sales_account'                   => 'sometimes|nullable|string|max:20',
            'sales_discount_account'          => 'sometimes|nullable|string|max:20',
            'prompt_payment_discount_account' => 'sometimes|nullable|string|max:20',
            'quote_valid_days'                => 'sometimes|integer|min:0',
            'delivery_required_by'            => 'sometimes|integer|min:0',
            // Suppliers and Purchasing
            'delivery_over_receive_allowance' => 'sometimes|numeric|min:0|max:100',
            'invoice_over_charge_allowance'   => 'sometimes|numeric|min:0|max:100',
            // Suppliers and Purchasing Defaults
            'payable_account'                 => 'sometimes|nullable|string|max:20',
            'purchase_discount_account'       => 'sometimes|nullable|string|max:20',
            'supplier_advance_account'        => 'sometimes|nullable|string|max:20',
            'grn_clearing_account'            => 'sometimes|nullable|string|max:20',
            'receival_required_by'            => 'sometimes|integer|min:0',
            'show_po_item_codes'              => 'sometimes|boolean',
            // Farmers (Dairy) Defaults
            'farmers_payable_account'         => 'sometimes|nullable|string|max:20',
            'farmers_bank_account'            => 'sometimes|nullable|string|max:20',
            'default_tare_kg'                 => 'sometimes|numeric|min:0',
            // Grader Payroll Defaults
            'grader_payroll_expense_account'      => 'sometimes|nullable|string|max:20',
            'graders_payable_account'             => 'sometimes|nullable|string|max:20',
            'grader_deductions_recovery_account'  => 'sometimes|nullable|string|max:20',
            // SACCO Defaults
            'sacco_savings_liability_account'     => 'sometimes|nullable|string|max:20',
            'sacco_share_capital_account'         => 'sometimes|nullable|string|max:20',
            'sacco_loans_receivable_account'      => 'sometimes|nullable|string|max:20',
            'sacco_loan_interest_income_account'  => 'sometimes|nullable|string|max:20',
            'sacco_cash_account'                  => 'sometimes|nullable|string|max:20',
            'sacco_savings_interest_rate_pct'     => 'sometimes|nullable|numeric|min:0|max:100',
            // Inventory
            'allow_negative_inventory'        => 'sometimes|boolean',
            'no_zero_amounts_service'         => 'sometimes|boolean',
            'location_notifications'          => 'sometimes|boolean',
            'allow_negative_prices'           => 'sometimes|boolean',
            // Items Defaults
            'items_sales_account'                   => 'sometimes|nullable|string|max:20',
            'items_inventory_account'               => 'sometimes|nullable|string|max:20',
            'items_cogs_account'                    => 'sometimes|nullable|string|max:20',
            'items_inventory_adjustments_account'   => 'sometimes|nullable|string|max:20',
            'items_wip_account'                     => 'sometimes|nullable|string|max:20',
            // Fixed Assets Defaults
            'loss_on_asset_disposal_account'  => 'sometimes|nullable|string|max:20',
            'depreciation_period'             => 'sometimes|string|in:yearly,monthly,quarterly',
            // Manufacturing Defaults
            'work_order_required_by_after'    => 'sometimes|integer|min:0',
            'vat_device_url'                  => 'sometimes|nullable|string|max:255',
        ]);

        $settings = GlSetting::first() ?? new GlSetting();
        $settings->fill($validated)->save();

        return ApiResponse::success($settings->fresh()->toArray(), 'GL settings updated successfully');
    }
}
