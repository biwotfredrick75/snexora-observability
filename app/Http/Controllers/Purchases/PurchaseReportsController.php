<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseReportsController extends Controller
{
    // Recognised AP transaction = a received GRN or posted invoice (mirrors SupplierAllocationController::inquiry()).
    private const AP_TYPES   = ['grn', 'invoice'];
    private const AP_STATUSES = ['received', 'ceo_approved'];

    // Allocations against an invoice/GRN are split across three tables (no single debtor_allocations
    // equivalent on the AP side) — this subquery unions them into one paid-amount-per-transaction set.
    private function allocatedSubquery(): \Illuminate\Database\Query\Expression
    {
        return DB::raw("(
            SELECT transaction_id, SUM(amt) AS paid FROM (
                SELECT transaction_id, this_allocation AS amt FROM payment_voucher_allocations
                    WHERE transaction_type IN ('Supplier Invoice','GRN Receipt')
                UNION ALL
                SELECT transaction_id, amount AS amt FROM supplier_credit_note_allocations
                    WHERE transaction_type IN ('Supplier Invoice','GRN Receipt')
                UNION ALL
                SELECT transaction_id, amount AS amt FROM supplier_journal_allocations
                    WHERE transaction_type IN ('Supplier Invoice','GRN Receipt')
            ) u GROUP BY transaction_id
        ) a");
    }

    // ── Shared form data ──────────────────────────────────────────────────────
    public function formData(): JsonResponse
    {
        return ApiResponse::success([
            'suppliers' => DB::table('suppliers')->where('inactive', false)
                ->orderBy('supplierName')->get(['supplierId as id', 'supplierName as name']),
            'locations' => DB::table('inventory_locations')->where('inactive', false)
                ->orderBy('name')->get(['id', 'name', 'code']),
            'po_statuses'    => ['draft', 'submitted', 'hod_approved', 'finance_approved', 'ceo_approved', 'rejected', 'received'],
            'voucher_types'  => ['bank', 'cash', 'cheque', 'mpesa'],
            'voucher_statuses' => ['draft', 'payables_approved', 'finance_approved', 'ceo_approved', 'posted'],
        ], 'Form data loaded');
    }

    // ── 1. Purchase Summary ───────────────────────────────────────────────────
    public function purchaseSummary(Request $r): JsonResponse
    {
        $from    = $r->get('date_from', now()->startOfMonth()->toDateString());
        $to      = $r->get('date_to',   now()->toDateString());
        $groupBy = $r->get('group_by',  'month');

        $periodExpr = match ($groupBy) {
            'day'   => "DATE_FORMAT(po.order_date, '%Y-%m-%d')",
            'week'  => "CONCAT(YEAR(po.order_date), '-W', LPAD(WEEK(po.order_date, 3), 2, '0'))",
            'year'  => "DATE_FORMAT(po.order_date, '%Y')",
            default => "DATE_FORMAT(po.order_date, '%Y-%m')",
        };

        $q = DB::table('purchase_orders as po')
            ->leftJoin('purchase_order_items as poi', 'poi.po_id', '=', 'po.id')
            ->whereIn('po.type', self::AP_TYPES)
            ->whereIn('po.status', self::AP_STATUSES)
            ->whereBetween('po.order_date', [$from, $to]);

        if ($r->filled('supplier_id')) $q->where('po.supplier_id', (int) $r->get('supplier_id'));
        if ($r->filled('location_id')) $q->where('po.location_id', (int) $r->get('location_id'));

        $rows = $q->selectRaw("
            {$periodExpr}                AS period,
            COUNT(DISTINCT po.id)        AS po_count,
            COUNT(DISTINCT po.supplier_id) AS supplier_count,
            SUM(poi.qty)                 AS total_qty,
            SUM(po.amount_total)         AS total_amount,
            AVG(po.amount_total)         AS avg_po
        ")
        ->groupByRaw($periodExpr)
        ->orderByRaw($periodExpr)
        ->get();

        return ApiResponse::success([
            'rows'   => $rows,
            'totals' => [
                'pos'       => $rows->sum('po_count'),
                'suppliers' => $rows->max('supplier_count'),
                'amount'    => round((float) $rows->sum('total_amount'), 2),
                'qty'       => round((float) $rows->sum('total_qty'), 2),
                'avg'       => $rows->count() > 0 ? round((float) $rows->avg('avg_po'), 2) : 0,
            ],
            'from' => $from, 'to' => $to, 'group_by' => $groupBy,
        ], 'Purchase summary loaded');
    }

    // ── 2. Purchase Orders Report ─────────────────────────────────────────────
    public function purchaseOrdersReport(Request $r): JsonResponse
    {
        $from = $r->get('date_from', now()->startOfMonth()->toDateString());
        $to   = $r->get('date_to',   now()->toDateString());

        $q = DB::table('purchase_orders as po')
            ->leftJoin('suppliers as s', 's.supplierId', '=', 'po.supplier_id')
            ->leftJoin('purchase_order_items as poi', 'poi.po_id', '=', 'po.id')
            ->where('po.type', 'po')
            ->whereBetween('po.order_date', [$from, $to]);

        if ($r->filled('supplier_id')) $q->where('po.supplier_id', (int) $r->get('supplier_id'));
        if ($r->filled('location_id')) $q->where('po.location_id', (int) $r->get('location_id'));
        if ($r->filled('status'))      $q->where('po.status',      $r->get('status'));

        $rows = $q->selectRaw('
            po.id, po.po_no,
            COALESCE(s.supplierName, \'Unknown\') AS supplier_name,
            po.order_date, po.delivery_date, po.due_date,
            po.status, po.amount_total,
            COUNT(poi.stock_id) AS item_count
        ')
        ->groupBy('po.id', 'po.po_no', 's.supplierName', 'po.order_date', 'po.delivery_date', 'po.due_date', 'po.status', 'po.amount_total')
        ->orderByDesc('po.order_date')
        ->get();

        return ApiResponse::success([
            'rows'   => $rows,
            'totals' => [
                'amount' => round((float) $rows->sum('amount_total'), 2),
                'count'  => $rows->count(),
                'avg'    => $rows->count() > 0 ? round((float) $rows->sum('amount_total') / $rows->count(), 2) : 0,
            ],
            'from' => $from, 'to' => $to,
        ], 'Purchase orders report loaded');
    }

    // ── 3. GRN Report ─────────────────────────────────────────────────────────
    public function grnReport(Request $r): JsonResponse
    {
        $from = $r->get('date_from', now()->startOfMonth()->toDateString());
        $to   = $r->get('date_to',   now()->toDateString());

        $q = DB::table('purchase_orders as po')
            ->leftJoin('suppliers as s', 's.supplierId', '=', 'po.supplier_id')
            ->where('po.type', 'grn')
            ->whereBetween('po.order_date', [$from, $to]);

        if ($r->filled('supplier_id')) $q->where('po.supplier_id', (int) $r->get('supplier_id'));
        if ($r->filled('location_id')) $q->where('po.location_id', (int) $r->get('location_id'));
        if ($r->filled('status'))      $q->where('po.status',      $r->get('status'));

        $rows = $q->selectRaw('
            po.id, po.po_no,
            COALESCE(s.supplierName, \'Unknown\') AS supplier_name,
            po.order_date AS delivery_date,
            po.reference, po.supplier_reference,
            po.status, po.amount_total
        ')
        ->orderByDesc('po.order_date')
        ->get();

        return ApiResponse::success([
            'rows'   => $rows,
            'totals' => [
                'amount' => round((float) $rows->sum('amount_total'), 2),
                'count'  => $rows->count(),
            ],
            'from' => $from, 'to' => $to,
        ], 'GRN report loaded');
    }

    // ── 4. Supplier Aged Analysis ─────────────────────────────────────────────
    public function supplierAgedAnalysis(Request $r): JsonResponse
    {
        $asOf = $r->get('as_of_date', now()->toDateString());

        $q = DB::table('purchase_orders as po')
            ->leftJoin('suppliers as s', 's.supplierId', '=', 'po.supplier_id')
            ->leftJoin($this->allocatedSubquery(), DB::raw('a.transaction_id'), '=', DB::raw('po.id'))
            ->whereIn('po.type', self::AP_TYPES)
            ->whereIn('po.status', self::AP_STATUSES)
            ->where('po.order_date', '<=', $asOf)
            ->whereRaw('(po.amount_total - IFNULL(a.paid,0)) > 0.005');

        if ($r->filled('supplier_id')) $q->where('po.supplier_id', (int) $r->get('supplier_id'));

        $dueExpr = 'COALESCE(po.due_date, po.order_date)';
        $rows = $q->selectRaw("
            po.supplier_id,
            COALESCE(s.supplierName, 'Unknown')                                                  AS supplier_name,
            SUM(po.amount_total - IFNULL(a.paid,0))                                               AS balance,
            SUM(CASE WHEN DATEDIFF(?, {$dueExpr}) <= 0
                THEN po.amount_total - IFNULL(a.paid,0) ELSE 0 END)                               AS `current`,
            SUM(CASE WHEN DATEDIFF(?, {$dueExpr}) BETWEEN 1  AND 30
                THEN po.amount_total - IFNULL(a.paid,0) ELSE 0 END)                               AS days_1_30,
            SUM(CASE WHEN DATEDIFF(?, {$dueExpr}) BETWEEN 31 AND 60
                THEN po.amount_total - IFNULL(a.paid,0) ELSE 0 END)                               AS days_31_60,
            SUM(CASE WHEN DATEDIFF(?, {$dueExpr}) BETWEEN 61 AND 90
                THEN po.amount_total - IFNULL(a.paid,0) ELSE 0 END)                               AS days_61_90,
            SUM(CASE WHEN DATEDIFF(?, {$dueExpr}) > 90
                THEN po.amount_total - IFNULL(a.paid,0) ELSE 0 END)                               AS days_90plus,
            COUNT(po.id)                                                                          AS invoice_count
        ", [$asOf, $asOf, $asOf, $asOf, $asOf])
        ->groupBy('po.supplier_id', 's.supplierName')
        ->orderByDesc('balance')
        ->get();

        return ApiResponse::success([
            'rows'   => $rows,
            'totals' => [
                'balance'    => round((float) $rows->sum('balance'), 2),
                'current'    => round((float) $rows->sum('current'), 2),
                'days_1_30'  => round((float) $rows->sum('days_1_30'), 2),
                'days_31_60' => round((float) $rows->sum('days_31_60'), 2),
                'days_61_90' => round((float) $rows->sum('days_61_90'), 2),
                'days_90plus'=> round((float) $rows->sum('days_90plus'), 2),
            ],
            'as_of' => $asOf,
        ], 'Supplier aged analysis loaded');
    }

    // ── 5. Supplier Statement ─────────────────────────────────────────────────
    public function supplierStatement(Request $r): JsonResponse
    {
        $supplierId = $r->get('supplier_id');
        $from       = $r->get('date_from', now()->startOfMonth()->toDateString());
        $to         = $r->get('date_to',   now()->toDateString());

        if (!$supplierId) {
            return ApiResponse::validationError(['supplier_id' => ['Supplier is required']]);
        }

        $supplier = DB::table('suppliers')->where('supplierId', $supplierId)
            ->first(['supplierName as name', 'supplierId as id', 'supplierReference as reference']);

        $invoices = DB::table('purchase_orders as po')
            ->where('po.supplier_id', $supplierId)
            ->whereIn('po.type', self::AP_TYPES)
            ->whereIn('po.status', self::AP_STATUSES)
            ->whereBetween('po.order_date', [$from, $to])
            ->selectRaw("po.order_date AS txn_date, po.po_no AS ref,
                         CASE po.type WHEN 'grn' THEN 'GRN Receipt' ELSE 'Invoice' END AS type,
                         po.amount_total AS debit, 0 AS credit, po.due_date")
            ->get();

        $payments = DB::table('payment_vouchers as pv')
            ->where('pv.supplier_id', $supplierId)
            ->where('pv.status', 'posted')
            ->whereBetween('pv.date_paid', [$from, $to])
            ->selectRaw("pv.date_paid AS txn_date, pv.pvn_no AS ref,
                         'Payment' AS type, 0 AS debit, pv.amount AS credit, NULL AS due_date")
            ->get();

        $creditNotes = DB::table('supplier_credit_notes as scn')
            ->where('scn.supplier_id', $supplierId)
            ->where('scn.status', 'posted')
            ->whereBetween('scn.date', [$from, $to])
            ->selectRaw("scn.date AS txn_date, scn.scn_no AS ref,
                         'Credit Note' AS type, 0 AS debit, scn.total AS credit, NULL AS due_date")
            ->get();

        $openingDebit  = (float) DB::table('purchase_orders')
            ->where('supplier_id', $supplierId)->whereIn('type', self::AP_TYPES)->whereIn('status', self::AP_STATUSES)
            ->where('order_date', '<', $from)->sum('amount_total');
        $openingCredit = (float) DB::table('payment_vouchers')
            ->where('supplier_id', $supplierId)->where('status', 'posted')->where('date_paid', '<', $from)->sum('amount');
        $openingCn     = (float) DB::table('supplier_credit_notes')
            ->where('supplier_id', $supplierId)->where('status', 'posted')->where('date', '<', $from)->sum('total');
        $openingBal    = $openingDebit - $openingCredit - $openingCn;

        $lines = $invoices->concat($payments)->concat($creditNotes)->sortBy('txn_date')->values();

        $balance = $openingBal;
        $lines = $lines->map(function ($line) use (&$balance) {
            $balance += (float) $line->debit - (float) $line->credit;
            return array_merge((array) $line, ['balance' => round($balance, 2)]);
        });

        return ApiResponse::success([
            'supplier'        => $supplier,
            'opening_balance' => round($openingBal, 2),
            'closing_balance' => round($balance, 2),
            'lines'           => $lines->values(),
            'from' => $from, 'to' => $to,
        ], 'Supplier statement loaded');
    }

    // ── 6. Outstanding POs ────────────────────────────────────────────────────
    public function outstandingPOs(Request $r): JsonResponse
    {
        $from        = $r->get('date_from', now()->startOfYear()->toDateString());
        $to          = $r->get('date_to',   now()->toDateString());
        $overdueOnly = $r->boolean('overdue_only', false);
        $today       = now()->toDateString();

        $q = DB::table('purchase_orders as po')
            ->leftJoin('suppliers as s', 's.supplierId', '=', 'po.supplier_id')
            ->leftJoin($this->allocatedSubquery(), DB::raw('a.transaction_id'), '=', DB::raw('po.id'))
            ->whereIn('po.type', self::AP_TYPES)
            ->whereIn('po.status', self::AP_STATUSES)
            ->whereBetween('po.order_date', [$from, $to])
            ->whereRaw('(po.amount_total - IFNULL(a.paid,0)) > 0.005');

        if ($overdueOnly)              $q->whereNotNull('po.due_date')->where('po.due_date', '<', $today);
        if ($r->filled('supplier_id')) $q->where('po.supplier_id', (int) $r->get('supplier_id'));
        if ($r->filled('location_id')) $q->where('po.location_id', (int) $r->get('location_id'));

        $rows = $q->selectRaw("
            po.id, po.po_no,
            COALESCE(s.supplierName, 'Unknown')        AS supplier_name,
            po.order_date, po.due_date,
            po.amount_total,
            IFNULL(a.paid, 0)                          AS paid_amount,
            (po.amount_total - IFNULL(a.paid,0))       AS balance,
            CASE WHEN po.due_date IS NULL THEN 0 WHEN DATEDIFF(?, po.due_date) > 0 THEN DATEDIFF(?, po.due_date) ELSE 0 END AS days_overdue
        ", [$today, $today])
        ->orderBy('po.due_date')
        ->get();

        return ApiResponse::success([
            'rows'   => $rows,
            'totals' => [
                'amount'  => round((float) $rows->sum('amount_total'), 2),
                'paid'    => round((float) $rows->sum('paid_amount'), 2),
                'balance' => round((float) $rows->sum('balance'), 2),
                'count'   => $rows->count(),
                'overdue' => round((float) $rows->where('days_overdue', '>', 0)->sum('balance'), 2),
            ],
            'from' => $from, 'to' => $to,
        ], 'Outstanding POs loaded');
    }

    // ── 7. Top Suppliers by Spend ─────────────────────────────────────────────
    public function topSuppliers(Request $r): JsonResponse
    {
        $from = $r->get('date_from', now()->startOfYear()->toDateString());
        $to   = $r->get('date_to',   now()->toDateString());
        $topN = max(1, (int) $r->get('top_n', 20));

        $q = DB::table('purchase_orders as po')
            ->leftJoin('suppliers as s', 's.supplierId', '=', 'po.supplier_id')
            ->whereIn('po.type', self::AP_TYPES)
            ->whereIn('po.status', self::AP_STATUSES)
            ->whereBetween('po.order_date', [$from, $to]);

        if ($r->filled('location_id')) $q->where('po.location_id', (int) $r->get('location_id'));

        $rows = $q->selectRaw('
            po.supplier_id,
            COALESCE(s.supplierName, \'Unknown\') AS supplier_name,
            COUNT(DISTINCT po.id)               AS po_count,
            SUM(po.amount_total)                AS total_amount,
            MIN(po.order_date)                  AS first_purchase,
            MAX(po.order_date)                  AS last_purchase
        ')
        ->groupBy('po.supplier_id', 's.supplierName')
        ->orderByDesc('total_amount')
        ->limit($topN)
        ->get();

        $grandAmt = (float) $rows->sum('total_amount');
        $rows = $rows->values()->map(fn ($row, $i) => array_merge((array) $row, [
            'rank'  => $i + 1,
            'share' => $grandAmt > 0 ? round((float) $row->total_amount / $grandAmt * 100, 1) : 0,
        ]));

        return ApiResponse::success([
            'rows'   => $rows,
            'totals' => [
                'amount'    => round($grandAmt, 2),
                'pos'       => $rows->sum('po_count'),
                'suppliers' => $rows->count(),
                'avg'       => $rows->count() > 0 ? round($grandAmt / $rows->count(), 2) : 0,
            ],
            'from' => $from, 'to' => $to, 'top_n' => $topN,
        ], 'Top suppliers loaded');
    }

    // ── 8. Voucher Payment Report ─────────────────────────────────────────────
    public function voucherPaymentReport(Request $r): JsonResponse
    {
        $from = $r->get('date_from', now()->startOfMonth()->toDateString());
        $to   = $r->get('date_to',   now()->toDateString());

        $q = DB::table('payment_vouchers as pv')
            ->leftJoin('suppliers as s', 's.supplierId', '=', 'pv.supplier_id')
            ->whereBetween('pv.date_paid', [$from, $to]);

        if ($r->filled('supplier_id')) $q->where('pv.supplier_id', (int) $r->get('supplier_id'));
        if ($r->filled('status'))      $q->where('pv.status',      $r->get('status'));
        if ($r->filled('type'))        $q->where('pv.type',        $r->get('type'));

        $rows = $q->selectRaw('
            pv.id, pv.pvn_no,
            COALESCE(s.supplierName, \'Unknown\') AS supplier_name,
            pv.date_paid, pv.type, pv.reference,
            pv.amount, pv.withholding_tax_amount, pv.status
        ')
        ->orderByDesc('pv.date_paid')
        ->get();

        return ApiResponse::success([
            'rows'   => $rows,
            'totals' => [
                'amount'        => round((float) $rows->sum('amount'), 2),
                'withholding'   => round((float) $rows->sum('withholding_tax_amount'), 2),
                'count'         => $rows->count(),
                'posted'        => $rows->where('status', 'posted')->count(),
            ],
            'from' => $from, 'to' => $to,
        ], 'Voucher payment report loaded');
    }

    // ── 9. Supplier Credit Notes ──────────────────────────────────────────────
    public function supplierCreditNotes(Request $r): JsonResponse
    {
        $from = $r->get('date_from', now()->startOfMonth()->toDateString());
        $to   = $r->get('date_to',   now()->toDateString());

        $q = DB::table('supplier_credit_notes as scn')
            ->leftJoin('suppliers as s', 's.supplierId', '=', 'scn.supplier_id')
            ->whereBetween('scn.date', [$from, $to]);

        if ($r->filled('supplier_id')) $q->where('scn.supplier_id', (int) $r->get('supplier_id'));
        if ($r->filled('status'))      $q->where('scn.status',      $r->get('status'));

        $rows = $q->selectRaw('
            scn.id, scn.scn_no,
            COALESCE(s.supplierName, \'Unknown\') AS supplier_name,
            scn.date, scn.due_date, scn.reference,
            scn.source_invoice_no, scn.total, scn.status
        ')
        ->orderByDesc('scn.date')
        ->get();

        return ApiResponse::success([
            'rows'   => $rows,
            'totals' => [
                'amount' => round((float) $rows->sum('total'), 2),
                'count'  => $rows->count(),
                'posted' => $rows->where('status', 'posted')->count(),
                'draft'  => $rows->where('status', 'draft')->count(),
            ],
            'from' => $from, 'to' => $to,
        ], 'Supplier credit notes loaded');
    }

    // ── CSV Export ────────────────────────────────────────────────────────────
    public function export(Request $r)
    {
        $report = $r->get('report', '');
        $name   = "{$report}-" . now()->format('Ymd-His') . '.csv';
        $title  = ucwords(str_replace('-', ' ', $report));

        [$headers, $rows] = match ($report) {
            'purchase-summary' => [
                ['Period', 'POs', 'Suppliers', 'Total Qty', 'Total Amount', 'Avg PO'],
                $this->purchaseSummary($r)->getData(true)['data']['rows'],
            ],
            'purchase-orders' => [
                ['PO#', 'Supplier', 'Order Date', 'Delivery Date', 'Due Date', 'Status', 'Amount', 'Items'],
                $this->purchaseOrdersReport($r)->getData(true)['data']['rows'],
            ],
            'grn-report' => [
                ['GRN#', 'Supplier', 'Delivery Date', 'Reference', 'Supplier Ref', 'Status', 'Amount'],
                $this->grnReport($r)->getData(true)['data']['rows'],
            ],
            'supplier-aged-analysis' => [
                ['Supplier', 'Balance', 'Current', '1-30 Days', '31-60 Days', '61-90 Days', '90+ Days', 'Invoices'],
                $this->supplierAgedAnalysis($r)->getData(true)['data']['rows'],
            ],
            'supplier-statement' => [
                ['Date', 'Ref', 'Type', 'Debit', 'Credit', 'Balance'],
                $this->supplierStatement($r)->getData(true)['data']['lines'],
            ],
            'outstanding-pos' => [
                ['PO#', 'Supplier', 'Order Date', 'Due Date', 'Amount', 'Paid', 'Balance', 'Days Overdue'],
                $this->outstandingPOs($r)->getData(true)['data']['rows'],
            ],
            'top-suppliers' => [
                ['Rank', 'Supplier', 'POs', 'Total Amount', 'First Purchase', 'Last Purchase', 'Share %'],
                $this->topSuppliers($r)->getData(true)['data']['rows'],
            ],
            'voucher-payment' => [
                ['Voucher#', 'Supplier', 'Date Paid', 'Type', 'Reference', 'Amount', 'Withholding Tax', 'Status'],
                $this->voucherPaymentReport($r)->getData(true)['data']['rows'],
            ],
            'supplier-credit-notes' => [
                ['SCN#', 'Supplier', 'Date', 'Due Date', 'Reference', 'Source Invoice#', 'Total', 'Status'],
                $this->supplierCreditNotes($r)->getData(true)['data']['rows'],
            ],
            default => [[], []],
        };

        return response()->stream(function () use ($rows, $headers, $title) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ["Purchases Report — {$title}"]);
            fputcsv($out, ["Generated: " . now()->format('d/m/Y H:i')]);
            fputcsv($out, []);
            fputcsv($out, $headers);
            foreach ((array) $rows as $row) {
                fputcsv($out, array_values((array) $row));
            }
            fclose($out);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$name}\"",
            'X-Accel-Buffering'   => 'no',
            'Cache-Control'       => 'no-cache',
        ]);
    }
}
