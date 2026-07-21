<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Points every gl_settings.*_account default (and the RAW MILK item's own
 * accounts) at real codes from the company's actual chart of accounts.
 *
 * Before this, gl_settings was almost entirely empty, so every module fell
 * back to its own ad-hoc hardcoded default scattered across the codebase —
 * several of which are not real account codes at all (literal strings like
 * 'INVENTORY', 'CASH', 'GRN-CLEARING', 'DEBTORS', 'PAYABLES', 'BANK_CHARGES'),
 * and one (PurchaseOrderController's old supplier-payable fallback) pointed
 * at 201010 "Farmers payables" — accidentally posting purchase-order payables
 * into the farmers' payables account. This seeder is the single place that
 * documents *why* each account was chosen.
 */
class GlDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // ── Balance sheet control accounts ─────────────────────────────
            'receivable_account'   => '104018', // Trade Debtors — the AR control account
            'payable_account'      => '202012', // Trade and other Payables — general AP control
            'farmers_payable_account' => '201010', // Farmers payables — exact match, what MilkPurchaseApprovalService owes farmers
            'farmers_bank_account'    => '102030', // "MILK PAYMENT ACCOUNT" — the account farmer milk payments are actually disbursed from
            'grn_clearing_account'    => '103036', // Goods Received A/C — clears between stock receipt and the supplier invoice
            'supplier_advance_account'=> '104021', // Prepayments — closest fit for advances paid to suppliers ahead of delivery
            // (was wrongly '101010' Land Acquisition Cost before this seeder — a fixed-asset account)

            // ── P&L / equity roll-up ────────────────────────────────────────
            'retained_earnings_account'  => '301012', // Retained Earnings
            'profit_loss_year_account'   => '301012', // no separate "current year P&L" account exists — rolls into Retained Earnings

            // ── Discounts ────────────────────────────────────────────────────
            'sales_discount_account'          => '401036', // Discount Allowed — given to customers
            'prompt_payment_discount_account' => '401036', // same account; early-settlement discount is a form of discount allowed
            'purchase_discount_account'       => '401037', // Discount Received — from suppliers

            // ── Bank / tax / misc P&L ──────────────────────────────────────
            'bank_charges_account'       => '501036', // Bank Charges — exact match
            'tax_deduction_account'      => '202017', // VAT WITHOLDING — withholding tax deducted at source
            'deferred_income_account'    => '201014', // Deferred Income — exact match
            'shipping_charged_account'   => '401035', // Other Incomes — no dedicated freight-income account exists
            'loss_on_asset_disposal_account' => '501057', // Miscellanous Expense account — infrequent, no dedicated disposal-loss account
            'exchange_variances_account' => '501057', // Miscellanous Expense account — this business trades in KES only; kept as a safe non-crashing default

            'sales_account'         => '401035', // Other Incomes — generic revenue fallback for invoices/deliveries/credit&debit notes when the item's own sales_account is blank

            // ── Generic item-level fallbacks (used only when an item's own
            // inventory_account/cogs_account/sales_account is blank) ─────────
            'items_inventory_account'             => '103039', // Inventory stores — generic raw/packaging stock
            'items_cogs_account'                  => '501010', // Cost of Sale Stores — generic COGS
            'items_sales_account'                 => '401035', // Other Incomes — generic sales fallback
            'items_inventory_adjustments_account' => '501078', // Inventory Adjustment A/C — write-offs/spillage/shrinkage
            'items_wip_account'                   => '103035', // Work In Progress A/C — exact match

            // ── POS payment-channel routing (columns added in the migration
            // just before this seeder runs — previously fell back to the
            // literal strings 'CASH'/'BANK'/'MPESA', none of which are real
            // account codes) ─────────────────────────────────────────────────
            'pos_cash_account'  => '102120', // CASH — the till/cash-in-hand account
            // pos_card_account / pos_mpesa_account intentionally left blank:
            // this company has ~90 real bank/paybill/till accounts (102031-
            // 102123) and no single one is "the" generic card or M-Pesa
            // account — set these explicitly in Setup > GL Settings to
            // whichever specific bank/paybill this POS location settles to.
        ];

        DB::table('gl_settings')->update($settings);

        // RAW MILK's own accounts — exact-name matches in this chart of
        // accounts, so item-level postings (milk purchase GRN/COGS/sales) use
        // these directly instead of the generic items_* fallbacks above.
        DB::table('items')->where('stock_id', 'MILK-RAW')->update([
            'inventory_account' => '103010', // Raw Milk (asset/inventory)
            'cogs_account'      => '501079', // Cost Of Sales Raw Milk
            'sales_account'     => '401010', // Raw Milk (income)
            'adjustment_account'=> '501078', // Inventory Adjustment A/C
        ]);
    }
}
