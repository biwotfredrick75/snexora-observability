<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-go-live cleanup: wipes transactional/document data while leaving every
 * setup/master table untouched (customers, suppliers, items, chart of
 * accounts, users/roles, locations, price lists, etc).
 */
class DataResetController extends Controller
{
    private const TABLES = [
        'Sales' => [
            'sales_quotation_items', 'sales_quotations',
            'sales_order_items', 'sales_orders',
            'sales_delivery_items', 'sales_deliveries',
            'sales_invoice_items', 'sales_invoices',
            'credit_note_items', 'credit_notes',
            'customer_deposits', 'customer_payments',
            'debtor_allocations', 'debtor_trans_details', 'debtor_transactions',
            'pos_transaction_items', 'pos_transactions',
            'mpesa_payments',
        ],
        'Purchases' => [
            'purchase_requisition_items', 'purchase_requisitions',
            'purchase_quotation_item_prices', 'purchase_quotation_suppliers', 'purchase_quotation_items', 'purchase_quotations',
            'purchase_order_items', 'purchase_orders',
            'supplier_credit_note_allocations', 'supplier_credit_note_gl_items', 'supplier_credit_note_items', 'supplier_credit_notes',
            'payment_voucher_allocations', 'payment_vouchers',
        ],
        'Farmers & Milk' => [
            'milk_purchase_items', 'milk_purchases',
            'milk_collection_sessions',
            'milk_grader_collections',
            'milk_grn_items', 'milk_grn_batches',
            'milk_location_transfers',
            'milk_purch_data', 'milk_purch_order_details', 'milk_purch_orders',
            'milk_supp_invoice_items', 'milk_supp_invoices',
            'milk_transactions',
            'farmer_direct_invoice_items', 'farmer_direct_invoices',
            'farmer_payments', 'farmer_payment_batches',
            'farmer_carry_forwards', 'farmer_checkoff_entries',
            'grader_advances',
            'weighbridge_readings',
        ],
        'Inventory' => [
            'stock_movements',
            'inventory_transfer_items', 'inventory_transfers',
            'inventory_adjustment_items', 'inventory_adjustments',
            'consumable_issue_items', 'consumable_issues',
            'stock_requisition_items', 'stock_requisitions',
            'stock_take_items', 'stock_takes',
            'packaging_receive_items', 'packaging_receives',
            'packaging_transfer_items', 'packaging_transfers',
        ],
        'Banking & GL' => [
            'gld_transactions',
            'bank_deposit_items', 'bank_deposits',
            'bank_payment_items', 'bank_payments',
            'bank_transfers',
            'bank_reconciliation_items', 'bank_reconciliations',
            'bank_statement_lines', 'bank_statement_imports',
            'journal_entry_lines', 'journal_entries', 'journals',
            'account_transfer_slips',
            'petty_cash_vouchers', 'petty_cash_replenishments', 'petty_cash_reconciliations', 'petty_cash_requests',
            'supplier_journal_allocations',
            'blockchain_anchors',
            'transaction_attachments',
        ],
        'Manufacturing' => [
            'production_plan_items', 'production_plans',
            'work_order_byproducts', 'work_order_labour', 'work_order_overhead',
            'work_order_stages', 'work_order_items', 'work_orders',
        ],
        'Casual Workers (transactions only)' => [
            'casual_worker_attendances',
            'casual_worker_pay_run_items', 'casual_worker_pay_runs',
            'casual_pay_postings',
        ],
        'Comments' => ['comments'],
    ];

    /** GET /api/setup/data-reset/counts — read-only, safe to call anytime */
    public function counts(): JsonResponse
    {
        $groups = [];
        foreach (self::TABLES as $group => $tables) {
            $tableCounts = [];
            $groupTotal = 0;
            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) continue;
                $count = DB::table($table)->count();
                $tableCounts[] = ['table' => $table, 'count' => $count];
                $groupTotal += $count;
            }
            $groups[] = ['group' => $group, 'tables' => $tableCounts, 'total' => $groupTotal];
        }

        return ApiResponse::success([
            'groups'      => $groups,
            'grand_total' => collect($groups)->sum('total'),
        ], 'Record counts retrieved');
    }

    /** POST /api/setup/data-reset/clear — destructive, requires typed confirmation */
    public function clear(Request $request): JsonResponse
    {
        $request->validate(['confirm' => 'required|string']);

        if ($request->confirm !== 'DELETE') {
            return ApiResponse::error('Type DELETE to confirm — nothing was cleared.', 422);
        }

        $allTables = collect(self::TABLES)->flatten()->values()
            ->filter(fn ($t) => Schema::hasTable($t))
            ->values()->all();

        // Count before wiping so the result shows what was cleared.
        $deleted = [];
        foreach ($allTables as $table) {
            $deleted[$table] = DB::table($table)->count();
        }

        // TRUNCATE is orders of magnitude faster than DELETE for large tables —
        // it skips row-level logging. It cannot run inside a transaction
        // (MySQL issues an implicit commit), so we guard with try/catch instead.
        try {
            DB::statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($allTables as $table) {
                DB::statement("TRUNCATE TABLE `{$table}`");
            }
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        } catch (\Throwable $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
            return ApiResponse::error('Failed to clear data — nothing was deleted: ' . $e->getMessage(), 500);
        }

        return ApiResponse::success([
            'deleted' => $deleted,
            'total'   => array_sum($deleted),
        ], 'Transactional data cleared successfully');
    }
}
