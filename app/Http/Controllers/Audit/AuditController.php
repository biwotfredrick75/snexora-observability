<?php

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AuditController extends Controller
{
    public function run(): JsonResponse
    {
        $checks = [];

        // ── 1. KRA eTIMS Compliance ─────────────────────────────────────────
        $unstampedInvoices = DB::table('sales_invoices')
            ->where('status', 'placed')
            ->whereNull('etims_status')
            ->select('id', 'inv_no', 'invoice_date', 'amount_total', 'debtor_no')
            ->orderBy('invoice_date')
            ->get();

        $checks[] = [
            'id'       => 'kra_invoices',
            'category' => 'KRA eTIMS Compliance',
            'severity' => $unstampedInvoices->count() > 0 ? 'critical' : 'passed',
            'title'    => 'Placed invoices not submitted to KRA eTIMS',
            'count'    => $unstampedInvoices->count(),
            'passed'   => $unstampedInvoices->isEmpty(),
            'items'    => $unstampedInvoices->map(fn($r) => [
                'ref'    => $r->inv_no,
                'date'   => $r->invoice_date,
                'amount' => (float) $r->amount_total,
                'detail' => "Customer: {$r->debtor_no}",
            ])->values(),
        ];

        $unstampedCns = DB::table('credit_notes')
            ->where('status', 'placed')
            ->whereNull('etims_status')
            ->select('id', 'cn_no', 'cn_date', 'amount_total', 'debtor_no')
            ->orderBy('cn_date')
            ->get();

        $checks[] = [
            'id'       => 'kra_credit_notes',
            'category' => 'KRA eTIMS Compliance',
            'severity' => $unstampedCns->count() > 0 ? 'critical' : 'passed',
            'title'    => 'Placed credit notes not submitted to KRA eTIMS',
            'count'    => $unstampedCns->count(),
            'passed'   => $unstampedCns->isEmpty(),
            'items'    => $unstampedCns->map(fn($r) => [
                'ref'    => $r->cn_no,
                'date'   => $r->cn_date,
                'amount' => (float) $r->amount_total,
                'detail' => "Customer: {$r->debtor_no}",
            ])->values(),
        ];

        $kraNoQr = DB::table('sales_invoices')
            ->whereIn('etims_status', ['signed', 'stamped'])
            ->whereNull('etims_qr_path')
            ->select('id', 'inv_no', 'invoice_date', 'etims_status')
            ->get();

        $checks[] = [
            'id'       => 'kra_missing_qr',
            'category' => 'KRA eTIMS Compliance',
            'severity' => $kraNoQr->count() > 0 ? 'warning' : 'passed',
            'title'    => 'eTIMS-stamped invoices missing QR code',
            'count'    => $kraNoQr->count(),
            'passed'   => $kraNoQr->isEmpty(),
            'items'    => $kraNoQr->map(fn($r) => [
                'ref'    => $r->inv_no,
                'date'   => $r->invoice_date,
                'amount' => null,
                'detail' => "Status: {$r->etims_status}",
            ])->values(),
        ];

        // ── 2. GL Integrity ─────────────────────────────────────────────────
        $unbalancedGl = DB::table('gld_transactions')
            ->select('trans_no', 'type', DB::raw('SUM(amount) as balance'))
            ->groupBy('trans_no', 'type')
            ->havingRaw('ABS(SUM(amount)) > 0.005')
            ->get();

        $checks[] = [
            'id'       => 'gl_balance',
            'category' => 'GL Integrity',
            'severity' => $unbalancedGl->count() > 0 ? 'critical' : 'passed',
            'title'    => 'Unbalanced GL transaction sets (debits ≠ credits)',
            'count'    => $unbalancedGl->count(),
            'passed'   => $unbalancedGl->isEmpty(),
            'items'    => $unbalancedGl->map(fn($r) => [
                'ref'    => "Trans #{$r->trans_no} / Type {$r->type}",
                'date'   => null,
                'amount' => (float) $r->balance,
                'detail' => "Net balance: " . number_format($r->balance, 4),
            ])->values(),
        ];

        $invoiceAmountMismatch = DB::table('sales_invoices as si')
            ->join(
                DB::raw('(SELECT inv_id, SUM(line_total) as items_total FROM sales_invoice_items GROUP BY inv_id) as it'),
                'si.id', '=', 'it.inv_id'
            )
            ->whereRaw('ABS(si.sub_total - it.items_total) > 0.005')
            ->select('si.id', 'si.inv_no', 'si.invoice_date', 'si.sub_total', 'it.items_total')
            ->get();

        $checks[] = [
            'id'       => 'invoice_totals',
            'category' => 'GL Integrity',
            'severity' => $invoiceAmountMismatch->count() > 0 ? 'warning' : 'passed',
            'title'    => 'Invoice sub-totals not matching sum of line items',
            'count'    => $invoiceAmountMismatch->count(),
            'passed'   => $invoiceAmountMismatch->isEmpty(),
            'items'    => $invoiceAmountMismatch->map(fn($r) => [
                'ref'    => $r->inv_no,
                'date'   => $r->invoice_date,
                'amount' => (float) $r->sub_total,
                'detail' => "Header: {$r->sub_total} | Items sum: {$r->items_total}",
            ])->values(),
        ];

        // ── 3. Receivables ───────────────────────────────────────────────────
        $overdueInvoices = DB::table('sales_invoices')
            ->where('status', 'placed')
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString())
            ->select('id', 'inv_no', 'invoice_date', 'due_date', 'amount_total', 'debtor_no')
            ->orderBy('due_date')
            ->get();

        $checks[] = [
            'id'       => 'overdue_invoices',
            'category' => 'Receivables',
            'severity' => $overdueInvoices->count() > 0 ? 'critical' : 'passed',
            'title'    => 'Overdue placed invoices (past due date)',
            'count'    => $overdueInvoices->count(),
            'passed'   => $overdueInvoices->isEmpty(),
            'items'    => $overdueInvoices->map(fn($r) => [
                'ref'    => $r->inv_no,
                'date'   => $r->due_date,
                'amount' => (float) $r->amount_total,
                'detail' => "Customer: {$r->debtor_no} | Due: {$r->due_date}",
            ])->values(),
        ];

        $overCreditLimit = DB::table('customers')
            ->where('credit_limit', '>', 0)
            ->whereRaw('current_credit > credit_limit')
            ->select('debtor_no', 'name', 'credit_limit', 'current_credit')
            ->orderByRaw('current_credit - credit_limit DESC')
            ->get();

        $checks[] = [
            'id'       => 'credit_limit_exceeded',
            'category' => 'Receivables',
            'severity' => $overCreditLimit->count() > 0 ? 'warning' : 'passed',
            'title'    => 'Customers exceeding their credit limit',
            'count'    => $overCreditLimit->count(),
            'passed'   => $overCreditLimit->isEmpty(),
            'items'    => $overCreditLimit->map(fn($r) => [
                'ref'    => $r->debtor_no,
                'date'   => null,
                'amount' => (float) $r->current_credit,
                'detail' => "{$r->name} | Limit: " . number_format($r->credit_limit, 2) . " | Balance: " . number_format($r->current_credit, 2),
            ])->values(),
        ];

        // ── 4. Data Integrity ────────────────────────────────────────────────
        $invoicesNoItems = DB::table('sales_invoices as si')
            ->leftJoin('sales_invoice_items as sii', 'si.id', '=', 'sii.inv_id')
            ->where('si.status', 'placed')
            ->whereNull('sii.id')
            ->select('si.id', 'si.inv_no', 'si.invoice_date', 'si.debtor_no')
            ->get();

        $checks[] = [
            'id'       => 'invoices_no_items',
            'category' => 'Data Integrity',
            'severity' => $invoicesNoItems->count() > 0 ? 'critical' : 'passed',
            'title'    => 'Placed invoices with no line items',
            'count'    => $invoicesNoItems->count(),
            'passed'   => $invoicesNoItems->isEmpty(),
            'items'    => $invoicesNoItems->map(fn($r) => [
                'ref'    => $r->inv_no,
                'date'   => $r->invoice_date,
                'amount' => null,
                'detail' => "Customer: {$r->debtor_no}",
            ])->values(),
        ];

        $itemsNoTaxType = DB::table('items')
            ->where('inactive', 0)
            ->where(function ($q) {
                $q->whereNull('etims_taxation_type')->orWhere('etims_taxation_type', '');
            })
            ->select('stock_id', 'description', 'category_id')
            ->orderBy('stock_id')
            ->limit(100)
            ->get();

        $itemsNoTaxTypeTotal = DB::table('items')
            ->where('inactive', 0)
            ->where(function ($q) {
                $q->whereNull('etims_taxation_type')->orWhere('etims_taxation_type', '');
            })
            ->count();

        $checks[] = [
            'id'       => 'items_no_tax_type',
            'category' => 'Data Integrity',
            'severity' => $itemsNoTaxTypeTotal > 0 ? 'warning' : 'passed',
            'title'    => 'Active items missing KRA eTIMS taxation type',
            'count'    => $itemsNoTaxTypeTotal,
            'passed'   => $itemsNoTaxTypeTotal === 0,
            'items'    => $itemsNoTaxType->map(fn($r) => [
                'ref'    => $r->stock_id,
                'date'   => null,
                'amount' => null,
                'detail' => $r->description,
            ])->values(),
            'truncated' => $itemsNoTaxTypeTotal > 100,
        ];

        $customersNoPin = DB::table('customers')
            ->where(function ($q) {
                $q->whereNull('kra_pin')->orWhere('kra_pin', '');
            })
            ->where('inactive', 0)
            ->select('debtor_no', 'name')
            ->orderBy('name')
            ->limit(50)
            ->get();

        $customersNoPinTotal = DB::table('customers')
            ->where(function ($q) {
                $q->whereNull('kra_pin')->orWhere('kra_pin', '');
            })
            ->where('inactive', 0)
            ->count();

        $checks[] = [
            'id'       => 'customers_no_pin',
            'category' => 'Data Integrity',
            'severity' => $customersNoPinTotal > 0 ? 'info' : 'passed',
            'title'    => 'Customers without KRA PIN (tax_id)',
            'count'    => $customersNoPinTotal,
            'passed'   => $customersNoPinTotal === 0,
            'items'    => $customersNoPin->map(fn($r) => [
                'ref'    => $r->debtor_no,
                'date'   => null,
                'amount' => null,
                'detail' => $r->name,
            ])->values(),
            'truncated' => $customersNoPinTotal > 50,
        ];

        // ── 5. Stock ─────────────────────────────────────────────────────────
        $negativeStock = DB::table('stock_movements as sm')
            ->join('items as i', 'sm.stock_id', '=', 'i.stock_id')
            ->where('i.inactive', 0)
            ->select('sm.stock_id', 'i.description', 'sm.loc_code', DB::raw('SUM(sm.qty) as qty_on_hand'))
            ->groupBy('sm.stock_id', 'i.description', 'sm.loc_code')
            ->havingRaw('SUM(sm.qty) < 0')
            ->orderByRaw('SUM(sm.qty) ASC')
            ->get();

        $checks[] = [
            'id'       => 'negative_stock',
            'category' => 'Stock',
            'severity' => $negativeStock->count() > 0 ? 'warning' : 'passed',
            'title'    => 'Items with negative stock quantity',
            'count'    => $negativeStock->count(),
            'passed'   => $negativeStock->isEmpty(),
            'items'    => $negativeStock->map(fn($r) => [
                'ref'    => $r->stock_id,
                'date'   => null,
                'amount' => (float) $r->qty_on_hand,
                'detail' => "{$r->description} @ {$r->loc_code}",
            ])->values(),
        ];

        // ── Summary ──────────────────────────────────────────────────────────
        $critical = collect($checks)->where('severity', 'critical')->count();
        $warnings = collect($checks)->where('severity', 'warning')->count();
        $passed   = collect($checks)->where('severity', 'passed')->count();
        $total    = count($checks);

        $healthScore = $total > 0 ? (int) round(($passed / $total) * 100) : 100;

        return ApiResponse::success([
            'summary' => [
                'total'        => $total,
                'critical'     => $critical,
                'warnings'     => $warnings,
                'passed'       => $passed,
                'health_score' => $healthScore,
                'run_at'       => now()->toISOString(),
            ],
            'checks' => $checks,
        ], 'Audit completed');
    }
}
