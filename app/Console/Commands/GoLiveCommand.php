<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Wipes all transactional data while preserving master/setup data.
 * Run this once before go-live to clear test/demo data.
 *
 * php8.3 artisan app:go-live --confirm
 */
class GoLiveCommand extends Command
{
    protected $signature = 'app:go-live
                            {--confirm : Skip the interactive confirmation prompt}
                            {--keep-audit : Preserve audit_trail records}
                            {--keep-mpesa : Preserve mpesa_payments records}';

    protected $description = 'Clear all transactional data for go-live (preserves master/setup data)';

    // Tables wiped in FK-safe order (children before parents).
    // Master/setup tables are NOT listed here — they are preserved.
    private const TRANSACTIONAL_TABLES = [
        // ── Allocations (must go first — reference invoices & payments) ────────
        'debtor_allocations',
        'debtor_trans_details',
        'debtor_transactions',

        // ── Sales ──────────────────────────────────────────────────────────────
        'sales_invoice_items',
        'sales_invoices',
        'sales_order_items',
        'sales_orders',
        'sales_delivery_items',
        'sales_deliveries',
        'sales_quotation_items',
        'sales_quotations',
        'credit_note_items',
        'credit_notes',

        // ── Customer payments & deposits ───────────────────────────────────────
        'customer_payments',
        'customer_deposits',

        // ── Purchases ──────────────────────────────────────────────────────────
        'payment_voucher_allocations',
        'payment_vouchers',
        'supplier_credit_note_allocations',
        'supplier_credit_note_gl_items',
        'supplier_credit_note_items',
        'supplier_credit_notes',
        'supplier_journal_allocations',
        'purchase_order_items',
        'purchase_orders',
        'purchase_quotation_item_prices',
        'purchase_quotation_items',
        'purchase_quotation_suppliers',
        'purchase_quotations',
        'purchase_requisition_items',
        'purchase_requisitions',

        // ── GL / Journals ──────────────────────────────────────────────────────
        'gld_transactions',
        'journal_entry_lines',
        'journal_entries',

        // ── Inventory ─────────────────────────────────────────────────────────
        'stock_movements',
        'inventory_adjustment_items',
        'inventory_adjustments',
        'inventory_transfer_items',
        'inventory_transfers',
        'stock_requisition_items',
        'stock_requisitions',
        'stock_take_items',
        'stock_takes',
        'store_allocations',
        'consumable_issue_items',
        'consumable_issues',

        // ── Milk operations ───────────────────────────────────────────────────
        'milk_purchase_items',
        'milk_grader_collections',
        'milk_purchases',
        'milk_grn_items',
        'milk_grn_batches',
        'milk_supp_invoice_items',
        'milk_supp_invoices',
        'milk_location_transfers',
        'milk_transactions',
        'milk_prices_per_member',

        // ── Farmers ───────────────────────────────────────────────────────────
        'farmer_checkoff_entries',
        'farmer_direct_invoice_items',
        'farmer_direct_invoices',
        'farmer_carry_forwards',
        'farmer_payment_batches',
        'farmer_payments',
        'grader_advances',

        // ── Manufacturing ─────────────────────────────────────────────────────
        'work_order_byproducts',
        'work_order_items',
        'work_order_labour',
        'work_order_overhead',
        'work_order_stages',
        'work_orders',
        'production_plan_items',
        'production_plans',

        // ── Packaging ─────────────────────────────────────────────────────────
        'packaging_receive_items',
        'packaging_receives',
        'packaging_transfer_items',
        'packaging_transfers',

        // ── Banking ───────────────────────────────────────────────────────────
        'bank_deposit_items',
        'bank_deposits',
        'bank_payment_items',
        'bank_payments',
        'bank_reconciliation_items',
        'bank_reconciliations',
        'bank_statement_lines',
        'bank_statement_imports',
        'bank_transfers',
        'account_transfer_slips',
        'petty_cash_requests',

        // ── POS ───────────────────────────────────────────────────────────────
        'pos_transaction_items',
        'pos_transactions',

        // ── HRM (leave transactions only — employees/departments preserved) ────
        'hrm_leave_requests',
        'hrm_leave_balances',

        // ── Misc ──────────────────────────────────────────────────────────────
        'weighbridge_readings',
        'blockchain_anchors',
        'transaction_attachments',
        'comments',
    ];

    // Tables cleared separately (always) — not in the main list
    private const SESSION_TABLES = [
        'oauth_access_tokens',
        'oauth_auth_codes',
        'oauth_refresh_tokens',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
    ];

    public function handle(): int
    {
        $this->newLine();
        $this->line('╔══════════════════════════════════════════════════════╗');
        $this->line('║           NEXORA ERP — GO-LIVE DATA WIPE            ║');
        $this->line('╚══════════════════════════════════════════════════════╝');
        $this->newLine();

        $this->warn('  This will permanently delete ALL transactional data:');
        $this->warn('  invoices, payments, purchases, milk, payroll, GL, etc.');
        $this->newLine();
        $this->info('  Preserved: users, customers, suppliers, farmers, items,');
        $this->info('  chart of accounts, inventory locations, setup data.');
        $this->newLine();

        if (! $this->option('confirm')) {
            if (! $this->confirm('  Type YES to confirm you want to wipe all transactional data', false)) {
                $this->error('  Aborted — no data was changed.');
                return self::FAILURE;
            }
        }

        $tables = self::TRANSACTIONAL_TABLES;

        if (! $this->option('keep-audit')) {
            $tables[] = 'audit_trails';
        }

        if (! $this->option('keep-mpesa')) {
            $tables[] = 'mpesa_payments';
        }

        $this->newLine();
        $this->info('  Disabling FK constraints...');
        $this->disableFkConstraints($tables);

        $this->info('  Clearing ' . count($tables) . ' transactional tables...');
        $this->newLine();

        $cleared = 0;
        $skipped = 0;
        $errors  = [];

        foreach ($tables as $table) {
            try {
                if (! $this->tableExists($table)) {
                    $this->line("  <comment>SKIP</comment>  {$table} (table not found)");
                    $skipped++;
                    continue;
                }

                $count = DB::table($table)->count();
                DB::table($table)->delete();
                $this->line("  <info>OK</info>    {$table} ({$count} rows removed)");
                $cleared++;
            } catch (\Throwable $e) {
                $this->line("  <error>FAIL</error>  {$table}: " . $e->getMessage());
                $errors[] = $table;
            }
        }

        $this->newLine();
        $this->info('  Re-enabling FK constraints...');
        $this->enableFkConstraints($tables);

        $this->newLine();
        $this->info('  Clearing session & queue tables...');
        foreach (self::SESSION_TABLES as $table) {
            try {
                if ($this->tableExists($table)) {
                    DB::table($table)->delete();
                }
            } catch (\Throwable) {
                // non-fatal
            }
        }

        $this->newLine();
        $this->line('══════════════════════════════════════════════════════');
        $this->info("  Done: {$cleared} tables cleared, {$skipped} skipped" . (count($errors) ? ', ' . count($errors) . ' failed' : '') . '.');

        if (count($errors)) {
            $this->warn('  Failed tables: ' . implode(', ', $errors));
            $this->warn('  These may need manual cleanup due to FK constraints.');
        }

        $this->newLine();
        $this->info('  Next steps:');
        $this->line('    1. Verify company preferences: Settings → Company');
        $this->line('    2. Set opening stock balances via Inventory Adjustments');
        $this->line('    3. Enter opening customer/supplier balances via GL Journals');
        $this->line('    4. Reset any milk prices/rates if needed');
        $this->newLine();

        return count($errors) ? self::FAILURE : self::SUCCESS;
    }

    private function tableExists(string $table): bool
    {
        return DB::select(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE='BASE TABLE' AND TABLE_NAME=?",
            [$table]
        ) !== [];
    }

    private function disableFkConstraints(array $tables): void
    {
        try {
            $placeholders = implode(',', array_fill(0, count($tables), '?'));
            $fks = DB::select("
                SELECT fk.name AS fk_name,
                       OBJECT_NAME(fk.parent_object_id) AS child_table
                FROM sys.foreign_keys fk
                INNER JOIN sys.foreign_key_columns fkc
                    ON fk.object_id = fkc.constraint_object_id
                WHERE OBJECT_NAME(fkc.referenced_object_id) IN ({$placeholders})
                   OR OBJECT_NAME(fk.parent_object_id) IN ({$placeholders})
            ", array_merge($tables, $tables));

            foreach ($fks as $fk) {
                DB::statement("ALTER TABLE [{$fk->child_table}] NOCHECK CONSTRAINT [{$fk->fk_name}]");
            }
        } catch (\Throwable $e) {
            $this->warn('  Could not disable FK constraints: ' . $e->getMessage());
        }
    }

    private function enableFkConstraints(array $tables): void
    {
        try {
            $placeholders = implode(',', array_fill(0, count($tables), '?'));
            $fks = DB::select("
                SELECT fk.name AS fk_name,
                       OBJECT_NAME(fk.parent_object_id) AS child_table
                FROM sys.foreign_keys fk
                INNER JOIN sys.foreign_key_columns fkc
                    ON fk.object_id = fkc.constraint_object_id
                WHERE OBJECT_NAME(fkc.referenced_object_id) IN ({$placeholders})
                   OR OBJECT_NAME(fk.parent_object_id) IN ({$placeholders})
            ", array_merge($tables, $tables));

            foreach ($fks as $fk) {
                DB::statement("ALTER TABLE [{$fk->child_table}] WITH CHECK CHECK CONSTRAINT [{$fk->fk_name}]");
            }
        } catch (\Throwable $e) {
            $this->warn('  Could not re-enable FK constraints: ' . $e->getMessage());
        }
    }
}
