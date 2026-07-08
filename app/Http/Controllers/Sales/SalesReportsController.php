<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesReportsController extends Controller
{
    // ── Shared form data ──────────────────────────────────────────────────────
    public function formData(): JsonResponse
    {
        return ApiResponse::success([
            'customers'    => DB::table('customers')->where('inactive', false)->orderBy('name')->get(['debtor_no', 'name']),
            'locations'    => DB::table('inventory_locations')->where('inactive', false)->orderBy('name')->get(['id', 'name', 'code']),
            'items'        => DB::table('items')->where('inactive', false)->orderBy('description')->get(['stock_id', 'description']),
            'categories'   => DB::table('item_categories')->where('inactive', false)->orderBy('name')->get(['id', 'name']),
            'salespersons' => DB::table('sales_persons')->orderBy('name')->get(['id', 'name']),
            'areas'        => DB::table('sales_areas')->orderBy('area_name')->get(['id', 'area_name as name']),
            'sales_types'  => DB::table('sales_types')->orderBy('type_name')->get(['id', 'type_name as name']),
            'cn_reasons'   => DB::table('credit_note_reasons')->orderBy('reason_name')->get(['id', 'reason_name as name']),
        ], 'Form data loaded');
    }

    // ── 1. Sales Summary ──────────────────────────────────────────────────────
    public function salesSummary(Request $r): JsonResponse
    {
        $from    = $r->get('date_from', now()->startOfMonth()->toDateString());
        $to      = $r->get('date_to',   now()->toDateString());
        $groupBy = $r->get('group_by',  'month');

        $dateFmt = match ($groupBy) {
            'day'   => "DATE_FORMAT(si.invoice_date, '%Y-%m-%d')",
            'week'  => "DATE_FORMAT(si.invoice_date, '%Y-W%u')",
            'year'  => "DATE_FORMAT(si.invoice_date, '%Y')",
            default => "DATE_FORMAT(si.invoice_date, '%Y-%m')",
        };

        $q = DB::table('sales_invoices as si')
            ->leftJoin('sales_invoice_items as sii', 'sii.inv_id', '=', 'si.id')
            ->where('si.status', 'placed')
            ->whereBetween('si.invoice_date', [$from, $to]);

        if ($r->filled('debtor_no'))   $q->where('si.debtor_no',   $r->get('debtor_no'));
        if ($r->filled('location_id')) $q->where('si.location_id', (int)$r->get('location_id'));

        $rows = $q->selectRaw("
            {$dateFmt}                   AS period,
            COUNT(DISTINCT si.id)        AS invoice_count,
            COUNT(DISTINCT si.debtor_no) AS customer_count,
            SUM(sii.qty)                 AS total_qty,
            SUM(sii.line_total)          AS total_amount,
            AVG(si.amount_total)         AS avg_invoice
        ")
        ->groupByRaw($dateFmt)
        ->orderByRaw($dateFmt)
        ->get();

        return ApiResponse::success([
            'rows'     => $rows,
            'totals'   => [
                'total_invoices'   => $rows->sum('invoice_count'),
                'active_customers' => $rows->max('customer_count'),
                'total_revenue'    => round((float)$rows->sum('total_amount'), 2),
                'total_qty'        => round((float)$rows->sum('total_qty'), 2),
                'avg_invoice'      => $rows->count() > 0 ? round((float)$rows->avg('avg_invoice'), 2) : 0,
            ],
            'from' => $from, 'to' => $to, 'group_by' => $groupBy,
        ], 'Sales summary loaded');
    }

    // ── 2. Sales by Customer ──────────────────────────────────────────────────
    public function salesByCustomer(Request $r): JsonResponse
    {
        $from = $r->get('date_from', now()->startOfMonth()->toDateString());
        $to   = $r->get('date_to',   now()->toDateString());

        $q = DB::table('sales_invoices as si')
            ->leftJoin('sales_invoice_items as sii', 'sii.inv_id',  '=', 'si.id')
            ->leftJoin('customers as c',             'c.debtor_no', '=', 'si.debtor_no')
            ->where('si.status', 'placed')
            ->whereBetween('si.invoice_date', [$from, $to]);

        if ($r->filled('debtor_no'))   $q->where('si.debtor_no',   $r->get('debtor_no'));
        if ($r->filled('location_id')) $q->where('si.location_id', (int)$r->get('location_id'));

        $rows = $q->selectRaw('
            si.debtor_no,
            COALESCE(c.name, si.debtor_no) AS customer_name,
            COUNT(DISTINCT si.id)          AS invoice_count,
            SUM(sii.qty)                   AS total_qty,
            SUM(sii.line_total)            AS total_amount,
            AVG(si.amount_total)           AS avg_invoice,
            MAX(si.invoice_date)           AS last_invoice
        ')
        ->groupBy('si.debtor_no', 'c.name')
        ->orderByDesc('total_amount')
        ->get();

        $grandAmt = (float)$rows->sum('total_amount');
        $rows = $rows->map(fn ($row) => array_merge((array)$row, [
            'share' => $grandAmt > 0 ? round((float)$row->total_amount / $grandAmt * 100, 1) : 0,
        ]));

        return ApiResponse::success([
            'rows'   => $rows->values(),
            'totals' => [
                'amount'    => round($grandAmt, 2),
                'invoices'  => $rows->sum('invoice_count'),
                'customers' => $rows->count(),
                'avg'       => $rows->count() > 0 ? round($grandAmt / $rows->count(), 2) : 0,
            ],
            'from' => $from, 'to' => $to,
        ], 'Sales by customer loaded');
    }

    // ── 3. Sales by Item ──────────────────────────────────────────────────────
    public function salesByItem(Request $r): JsonResponse
    {
        $from = $r->get('date_from', now()->startOfMonth()->toDateString());
        $to   = $r->get('date_to',   now()->toDateString());

        $q = DB::table('sales_invoice_items as sii')
            ->join('sales_invoices as si',       'si.id',        '=', 'sii.inv_id')
            ->leftJoin('items as it',            'it.stock_id',  '=', 'sii.stock_id')
            ->leftJoin('item_categories as ic',  'ic.id',        '=', 'it.category_id')
            ->where('si.status', 'placed')
            ->whereBetween('si.invoice_date', [$from, $to]);

        if ($r->filled('category_id')) $q->where('it.category_id',  (int)$r->get('category_id'));
        if ($r->filled('stock_id'))    $q->where('sii.stock_id',    $r->get('stock_id'));
        if ($r->filled('location_id')) $q->where('si.location_id',  (int)$r->get('location_id'));

        $rows = $q->selectRaw('
            sii.stock_id,
            sii.description                    AS item_description,
            COALESCE(ic.name,"Uncategorised")  AS category_name,
            COUNT(DISTINCT si.id)              AS invoice_count,
            SUM(sii.qty)                       AS total_qty,
            SUM(sii.line_total)                AS total_amount,
            AVG(sii.price)                     AS avg_price,
            MAX(si.invoice_date)               AS last_invoice
        ')
        ->groupBy('sii.stock_id', 'sii.description', 'ic.name')
        ->orderByDesc('total_amount')
        ->get();

        $grandAmt = (float)$rows->sum('total_amount');
        $rows = $rows->map(fn ($row) => array_merge((array)$row, [
            'share' => $grandAmt > 0 ? round((float)$row->total_amount / $grandAmt * 100, 1) : 0,
        ]));

        return ApiResponse::success([
            'rows'   => $rows->values(),
            'totals' => [
                'amount'   => round($grandAmt, 2),
                'qty'      => round((float)$rows->sum('total_qty'), 2),
                'items'    => $rows->count(),
                'invoices' => $rows->sum('invoice_count'),
            ],
            'from' => $from, 'to' => $to,
        ], 'Sales by item loaded');
    }

    // ── 4. Sales by Salesperson ───────────────────────────────────────────────
    public function salesBySalesperson(Request $r): JsonResponse
    {
        $from = $r->get('date_from', now()->startOfMonth()->toDateString());
        $to   = $r->get('date_to',   now()->toDateString());

        $q = DB::table('sales_invoices as si')
            ->join('sales_invoice_items as sii', 'sii.inv_id', '=', 'si.id')
            ->leftJoin(DB::raw('(SELECT debtor_no, MIN(id) as min_id FROM customer_branches GROUP BY debtor_no) cb_min'), 'cb_min.debtor_no', '=', 'si.debtor_no')
            ->leftJoin('customer_branches as cb', 'cb.id', '=', 'cb_min.min_id')
            ->leftJoin('sales_persons as sp',     'sp.id', '=', 'cb.sales_person_id')
            ->where('si.status', 'placed')
            ->whereBetween('si.invoice_date', [$from, $to]);

        if ($r->filled('salesperson_id')) $q->where('cb.sales_person_id', (int)$r->get('salesperson_id'));
        if ($r->filled('debtor_no'))      $q->where('si.debtor_no',       $r->get('debtor_no'));

        $rows = $q->selectRaw('
            COALESCE(sp.id, 0)                 AS salesperson_id,
            COALESCE(sp.name, "Unassigned")    AS salesperson_name,
            COUNT(DISTINCT si.debtor_no)       AS customer_count,
            COUNT(DISTINCT si.id)              AS invoice_count,
            SUM(sii.qty)                       AS total_qty,
            SUM(sii.line_total)                AS total_amount,
            MAX(si.invoice_date)               AS last_invoice
        ')
        ->groupBy('sp.id', 'sp.name')
        ->orderByDesc('total_amount')
        ->get();

        $grandAmt = (float)$rows->sum('total_amount');
        $rows = $rows->map(fn ($row) => array_merge((array)$row, [
            'share' => $grandAmt > 0 ? round((float)$row->total_amount / $grandAmt * 100, 1) : 0,
        ]));

        return ApiResponse::success([
            'rows'   => $rows->values(),
            'totals' => [
                'amount'      => round($grandAmt, 2),
                'invoices'    => $rows->sum('invoice_count'),
                'salespersons'=> $rows->count(),
                'top'         => $rows->first()?->salesperson_name ?? '-',
            ],
            'from' => $from, 'to' => $to,
        ], 'Sales by salesperson loaded');
    }

    // ── 5. Sales by Area ──────────────────────────────────────────────────────
    public function salesByArea(Request $r): JsonResponse
    {
        $from = $r->get('date_from', now()->startOfMonth()->toDateString());
        $to   = $r->get('date_to',   now()->toDateString());

        $q = DB::table('sales_invoices as si')
            ->join('sales_invoice_items as sii', 'sii.inv_id', '=', 'si.id')
            ->leftJoin(DB::raw('(SELECT debtor_no, MIN(id) as min_id FROM customer_branches GROUP BY debtor_no) cb_min'), 'cb_min.debtor_no', '=', 'si.debtor_no')
            ->leftJoin('customer_branches as cb', 'cb.id', '=', 'cb_min.min_id')
            ->leftJoin('sales_areas as sa',       'sa.id', '=', 'cb.sales_area_id')
            ->where('si.status', 'placed')
            ->whereBetween('si.invoice_date', [$from, $to]);

        if ($r->filled('area_id'))   $q->where('cb.sales_area_id', (int)$r->get('area_id'));
        if ($r->filled('debtor_no')) $q->where('si.debtor_no',     $r->get('debtor_no'));

        $rows = $q->selectRaw('
            COALESCE(sa.id, 0)                 AS area_id,
            COALESCE(sa.area_name,"Unassigned")AS area_name,
            COUNT(DISTINCT si.debtor_no)       AS customer_count,
            COUNT(DISTINCT si.id)              AS invoice_count,
            SUM(sii.qty)                       AS total_qty,
            SUM(sii.line_total)                AS total_amount,
            MAX(si.invoice_date)               AS last_invoice
        ')
        ->groupBy('sa.id', 'sa.area_name')
        ->orderByDesc('total_amount')
        ->get();

        $grandAmt = (float)$rows->sum('total_amount');
        $rows = $rows->map(fn ($row) => array_merge((array)$row, [
            'share' => $grandAmt > 0 ? round((float)$row->total_amount / $grandAmt * 100, 1) : 0,
        ]));

        return ApiResponse::success([
            'rows'   => $rows->values(),
            'totals' => [
                'amount'    => round($grandAmt, 2),
                'invoices'  => $rows->sum('invoice_count'),
                'areas'     => $rows->count(),
                'top_area'  => $rows->first()?->area_name ?? '-',
            ],
            'from' => $from, 'to' => $to,
        ], 'Sales by area loaded');
    }

    // ── 6. Customer Aged Analysis ─────────────────────────────────────────────
    public function customerAgedAnalysis(Request $r): JsonResponse
    {
        $asOf = $r->get('as_of_date', now()->toDateString());

        $q = DB::table('sales_invoices as si')
            ->leftJoin('customers as c', 'c.debtor_no', '=', 'si.debtor_no')
            ->leftJoin(DB::raw('(SELECT inv_id, SUM(amount) AS paid FROM debtor_allocations GROUP BY inv_id) da'), 'da.inv_id', '=', 'si.id')
            ->where('si.status', 'placed')
            ->where('si.invoice_date', '<=', $asOf)
            ->whereRaw('(si.amount_total - COALESCE(da.paid,0)) > 0.005');

        if ($r->filled('debtor_no')) $q->where('si.debtor_no', $r->get('debtor_no'));

        $rows = $q->selectRaw("
            si.debtor_no,
            COALESCE(c.name, si.debtor_no)                                                  AS customer_name,
            SUM(si.amount_total - COALESCE(da.paid,0))                                      AS balance,
            SUM(CASE WHEN DATEDIFF(?,si.due_date) <= 0
                THEN si.amount_total-COALESCE(da.paid,0) ELSE 0 END)                        AS current_due,
            SUM(CASE WHEN DATEDIFF(?,si.due_date) BETWEEN 1  AND 30
                THEN si.amount_total-COALESCE(da.paid,0) ELSE 0 END)                        AS days_1_30,
            SUM(CASE WHEN DATEDIFF(?,si.due_date) BETWEEN 31 AND 60
                THEN si.amount_total-COALESCE(da.paid,0) ELSE 0 END)                        AS days_31_60,
            SUM(CASE WHEN DATEDIFF(?,si.due_date) BETWEEN 61 AND 90
                THEN si.amount_total-COALESCE(da.paid,0) ELSE 0 END)                        AS days_61_90,
            SUM(CASE WHEN DATEDIFF(?,si.due_date) > 90
                THEN si.amount_total-COALESCE(da.paid,0) ELSE 0 END)                        AS days_over_90,
            COUNT(si.id)                                                                     AS invoice_count
        ", [$asOf, $asOf, $asOf, $asOf, $asOf])
        ->groupBy('si.debtor_no', 'c.name')
        ->orderByDesc('balance')
        ->get();

        return ApiResponse::success([
            'rows'   => $rows,
            'totals' => [
                'balance'      => round((float)$rows->sum('balance'), 2),
                'current_due'  => round((float)$rows->sum('current_due'), 2),
                'days_1_30'    => round((float)$rows->sum('days_1_30'), 2),
                'days_31_60'   => round((float)$rows->sum('days_31_60'), 2),
                'days_61_90'   => round((float)$rows->sum('days_61_90'), 2),
                'days_over_90' => round((float)$rows->sum('days_over_90'), 2),
            ],
            'as_of' => $asOf,
        ], 'Customer aged analysis loaded');
    }

    // ── 7. Customer Statement ─────────────────────────────────────────────────
    public function customerStatement(Request $r): JsonResponse
    {
        $debtorNo = $r->get('debtor_no');
        $from     = $r->get('date_from', now()->startOfMonth()->toDateString());
        $to       = $r->get('date_to',   now()->toDateString());

        if (!$debtorNo) {
            return ApiResponse::validationError(['debtor_no' => ['Customer is required']]);
        }

        $customer = DB::table('customers')->where('debtor_no', $debtorNo)
            ->first(['name', 'debtor_no', 'email', 'phone', 'address']);

        $invoices = DB::table('sales_invoices as si')
            ->where('si.debtor_no', $debtorNo)
            ->where('si.status', 'placed')
            ->whereBetween('si.invoice_date', [$from, $to])
            ->selectRaw("si.invoice_date AS txn_date, si.inv_no AS ref,
                         'Invoice' AS type, si.amount_total AS debit, 0 AS credit, si.due_date")
            ->get();

        $payments = DB::table('customer_payments as cp')
            ->where('cp.debtor_no', $debtorNo)
            ->where('cp.status', 'posted')
            ->whereBetween('cp.payment_date', [$from, $to])
            ->selectRaw("cp.payment_date AS txn_date, cp.payment_no AS ref,
                         'Payment' AS type, 0 AS debit, cp.amount AS credit, NULL AS due_date")
            ->get();

        $creditNotes = DB::table('credit_notes as cn')
            ->where('cn.debtor_no', $debtorNo)
            ->where('cn.status', 'placed')
            ->whereBetween('cn.cn_date', [$from, $to])
            ->selectRaw("cn.cn_date AS txn_date, cn.cn_no AS ref,
                         'Credit Note' AS type, 0 AS debit, cn.amount_total AS credit, NULL AS due_date")
            ->get();

        // Opening balance before date range
        $openingDebit  = (float) DB::table('sales_invoices')
            ->where('debtor_no', $debtorNo)->where('status', 'placed')->where('invoice_date', '<', $from)
            ->sum('amount_total');
        $openingCredit = (float) DB::table('customer_payments')
            ->where('debtor_no', $debtorNo)->where('status', 'posted')->where('payment_date', '<', $from)
            ->sum('amount');
        $openingCn     = (float) DB::table('credit_notes')
            ->where('debtor_no', $debtorNo)->where('status', 'placed')->where('cn_date', '<', $from)
            ->sum('amount_total');
        $openingBal    = $openingDebit - $openingCredit - $openingCn;

        $lines = $invoices->concat($payments)->concat($creditNotes)->sortBy('txn_date')->values();

        $balance = $openingBal;
        $lines = $lines->map(function ($line) use (&$balance) {
            $balance += (float)$line->debit - (float)$line->credit;
            return array_merge((array)$line, ['balance' => round($balance, 2)]);
        });

        return ApiResponse::success([
            'customer'        => $customer,
            'opening_balance' => round($openingBal, 2),
            'closing_balance' => round($balance, 2),
            'lines'           => $lines->values(),
            'from' => $from, 'to' => $to,
        ], 'Customer statement loaded');
    }

    // ── 8. Outstanding Invoices ───────────────────────────────────────────────
    public function outstandingInvoices(Request $r): JsonResponse
    {
        $from        = $r->get('date_from', now()->startOfYear()->toDateString());
        $to          = $r->get('date_to',   now()->toDateString());
        $overdueOnly = $r->boolean('overdue_only', false);
        $today       = now()->toDateString();

        $q = DB::table('sales_invoices as si')
            ->leftJoin('customers as c', 'c.debtor_no', '=', 'si.debtor_no')
            ->leftJoin(DB::raw('(SELECT inv_id, SUM(amount) AS paid FROM debtor_allocations GROUP BY inv_id) da'), 'da.inv_id', '=', 'si.id')
            ->where('si.status', 'placed')
            ->whereBetween('si.invoice_date', [$from, $to])
            ->whereRaw('(si.amount_total - COALESCE(da.paid,0)) > 0.005');

        if ($overdueOnly)              $q->where('si.due_date', '<', $today);
        if ($r->filled('debtor_no'))   $q->where('si.debtor_no',   $r->get('debtor_no'));
        if ($r->filled('location_id')) $q->where('si.location_id', (int)$r->get('location_id'));

        $rows = $q->selectRaw("
            si.id,
            si.inv_no,
            COALESCE(c.name, si.debtor_no)             AS customer_name,
            si.invoice_date,
            si.due_date,
            si.amount_total,
            COALESCE(da.paid, 0)                       AS paid_amount,
            (si.amount_total - COALESCE(da.paid,0))    AS balance,
            CASE WHEN DATEDIFF(?, si.due_date) > 0 THEN DATEDIFF(?, si.due_date) ELSE 0 END AS days_overdue
        ", [$today, $today])
        ->orderBy('si.due_date')
        ->get();

        return ApiResponse::success([
            'rows'   => $rows,
            'totals' => [
                'amount'   => round((float)$rows->sum('amount_total'), 2),
                'paid'     => round((float)$rows->sum('paid_amount'), 2),
                'balance'  => round((float)$rows->sum('balance'), 2),
                'count'    => $rows->count(),
                'overdue'  => round((float)$rows->where('days_overdue', '>', 0)->sum('balance'), 2),
            ],
            'from' => $from, 'to' => $to,
        ], 'Outstanding invoices loaded');
    }

    // ── 9. Price List Report ──────────────────────────────────────────────────
    public function priceList(Request $r): JsonResponse
    {
        $q = DB::table('item_sales_prices as isp')
            ->join('items as it',              'it.id',  '=', 'isp.item_id')
            ->leftJoin('item_categories as ic','ic.id',  '=', 'it.category_id')
            ->where('it.inactive', false);

        if ($r->filled('category_id')) $q->where('it.category_id', (int)$r->get('category_id'));
        if ($r->filled('stock_id'))    $q->where('it.stock_id',    $r->get('stock_id'));
        if ($r->filled('sales_type'))  $q->where('isp.sales_type', $r->get('sales_type'));

        $rows = $q->selectRaw('
            it.stock_id,
            it.description,
            COALESCE(ic.name,"Uncategorised") AS category_name,
            isp.sales_type,
            isp.currency,
            isp.price
        ')
        ->orderBy('it.description')
        ->orderBy('isp.sales_type')
        ->get();

        return ApiResponse::success([
            'rows'        => $rows,
            'sales_types' => $rows->pluck('sales_type')->unique()->sort()->values(),
            'totals'      => [
                'items'    => $rows->pluck('stock_id')->unique()->count(),
                'entries'  => $rows->count(),
                'avg_price'=> $rows->count() > 0 ? round((float)$rows->avg('price'), 2) : 0,
            ],
        ], 'Price list loaded');
    }

    // ── 10. Delivery Status ───────────────────────────────────────────────────
    public function deliveryStatus(Request $r): JsonResponse
    {
        $from = $r->get('date_from', now()->startOfMonth()->toDateString());
        $to   = $r->get('date_to',   now()->toDateString());

        $q = DB::table('sales_deliveries as sd')
            ->leftJoin('customers as c',          'c.debtor_no',  '=', 'sd.debtor_no')
            ->leftJoin('inventory_locations as l', 'l.id',        '=', 'sd.location_id')
            ->leftJoin('sales_invoices as si',     'si.dn_id',    '=', 'sd.id')
            ->whereBetween('sd.delivery_date', [$from, $to]);

        if ($r->filled('debtor_no'))   $q->where('sd.debtor_no',   $r->get('debtor_no'));
        if ($r->filled('location_id')) $q->where('sd.location_id', (int)$r->get('location_id'));
        if ($r->filled('status'))      $q->where('sd.status',      $r->get('status'));

        $rows = $q->selectRaw('
            sd.id,
            sd.dn_no,
            COALESCE(c.name, sd.debtor_no)  AS customer_name,
            sd.delivery_date,
            sd.amount_total,
            sd.status,
            COALESCE(l.name, "Unknown")     AS location_name,
            MAX(si.inv_no)                  AS invoice_no,
            sd.deliver_to
        ')
        ->groupBy('sd.id','sd.dn_no','c.name','sd.debtor_no','sd.delivery_date',
                  'sd.amount_total','sd.status','l.name','sd.deliver_to')
        ->orderByDesc('sd.delivery_date')
        ->get();

        return ApiResponse::success([
            'rows'   => $rows,
            'totals' => [
                'amount'   => round((float)$rows->sum('amount_total'), 2),
                'count'    => $rows->count(),
                'invoiced' => $rows->filter(fn ($row) => !empty($row->invoice_no))->count(),
                'pending'  => $rows->filter(fn ($row) => empty($row->invoice_no))->count(),
            ],
            'from' => $from, 'to' => $to,
        ], 'Delivery status loaded');
    }

    // ── 11. Credit Notes ──────────────────────────────────────────────────────
    public function creditNotes(Request $r): JsonResponse
    {
        $from = $r->get('date_from', now()->startOfMonth()->toDateString());
        $to   = $r->get('date_to',   now()->toDateString());

        $q = DB::table('credit_notes as cn')
            ->leftJoin('customers as c',            'c.debtor_no', '=', 'cn.debtor_no')
            ->leftJoin('sales_invoices as si',      'si.id',       '=', 'cn.inv_id')
            ->leftJoin('credit_note_reasons as cr', 'cr.id',       '=', 'cn.reason_id')
            ->where('cn.status', 'placed')
            ->whereBetween('cn.cn_date', [$from, $to]);

        if ($r->filled('debtor_no')) $q->where('cn.debtor_no', $r->get('debtor_no'));
        if ($r->filled('cn_type'))   $q->where('cn.cn_type',   $r->get('cn_type'));
        if ($r->filled('reason_id')) $q->where('cn.reason_id', (int)$r->get('reason_id'));

        $rows = $q->selectRaw('
            cn.id,
            cn.cn_no,
            COALESCE(c.name, cn.debtor_no)    AS customer_name,
            cn.cn_date,
            cn.cn_type,
            cn.amount_total,
            COALESCE(si.inv_no, "-")          AS invoice_no,
            COALESCE(cr.reason_name, "-")     AS reason_name
        ')
        ->orderByDesc('cn.cn_date')
        ->get();

        return ApiResponse::success([
            'rows'   => $rows,
            'totals' => [
                'amount'   => round((float)$rows->sum('amount_total'), 2),
                'count'    => $rows->count(),
                'returns'  => $rows->where('cn_type', 'return')->count(),
                'discounts'=> $rows->where('cn_type', 'discount')->count(),
            ],
            'from' => $from, 'to' => $to,
        ], 'Credit notes loaded');
    }

    // ── 12. Top Customers ─────────────────────────────────────────────────────
    public function topCustomers(Request $r): JsonResponse
    {
        $from = $r->get('date_from', now()->startOfYear()->toDateString());
        $to   = $r->get('date_to',   now()->toDateString());
        $topN = max(1, (int)$r->get('top_n', 20));

        $q = DB::table('sales_invoices as si')
            ->join('sales_invoice_items as sii', 'sii.inv_id',  '=', 'si.id')
            ->leftJoin('customers as c',         'c.debtor_no', '=', 'si.debtor_no')
            ->where('si.status', 'placed')
            ->whereBetween('si.invoice_date', [$from, $to]);

        if ($r->filled('location_id')) $q->where('si.location_id', (int)$r->get('location_id'));

        $rows = $q->selectRaw('
            si.debtor_no,
            COALESCE(c.name, si.debtor_no) AS customer_name,
            COUNT(DISTINCT si.id)          AS invoice_count,
            SUM(sii.qty)                   AS total_qty,
            SUM(sii.line_total)            AS total_amount,
            MIN(si.invoice_date)           AS first_invoice,
            MAX(si.invoice_date)           AS last_invoice
        ')
        ->groupBy('si.debtor_no', 'c.name')
        ->orderByDesc('total_amount')
        ->limit($topN)
        ->get();

        $grandAmt = (float)$rows->sum('total_amount');
        $rows = $rows->values()->map(fn ($row, $i) => array_merge((array)$row, [
            'rank'  => $i + 1,
            'share' => $grandAmt > 0 ? round((float)$row->total_amount / $grandAmt * 100, 1) : 0,
        ]));

        return ApiResponse::success([
            'rows'   => $rows,
            'totals' => [
                'amount'    => round($grandAmt, 2),
                'invoices'  => $rows->sum('invoice_count'),
                'customers' => $rows->count(),
                'avg'       => $rows->count() > 0 ? round($grandAmt / $rows->count(), 2) : 0,
            ],
            'from' => $from, 'to' => $to, 'top_n' => $topN,
        ], 'Top customers loaded');
    }

    // ── 13. Sales Variance ────────────────────────────────────────────────────
    public function salesVariance(Request $r): JsonResponse
    {
        $p1From  = $r->get('p1_from', now()->subMonth()->startOfMonth()->toDateString());
        $p1To    = $r->get('p1_to',   now()->subMonth()->endOfMonth()->toDateString());
        $p2From  = $r->get('p2_from', now()->startOfMonth()->toDateString());
        $p2To    = $r->get('p2_to',   now()->toDateString());
        $groupBy = $r->get('group_by', 'customer');

        $fetch = function (string $from, string $to) use ($groupBy) {
            $q = DB::table('sales_invoices as si')
                ->join('sales_invoice_items as sii', 'sii.inv_id',  '=', 'si.id')
                ->leftJoin('customers as c',         'c.debtor_no', '=', 'si.debtor_no')
                ->where('si.status', 'placed')
                ->whereBetween('si.invoice_date', [$from, $to]);

            if ($groupBy === 'item') {
                return $q->selectRaw('sii.stock_id AS key_id, sii.description AS key_name,
                    SUM(sii.line_total) AS amount, SUM(sii.qty) AS qty, COUNT(DISTINCT si.id) AS invoices')
                    ->groupBy('sii.stock_id','sii.description')->get()->keyBy('key_id');
            }
            if ($groupBy === 'area') {
                $q->leftJoin(DB::raw('(SELECT debtor_no, MIN(id) as min_id FROM customer_branches GROUP BY debtor_no) cb_min'), 'cb_min.debtor_no', '=', 'si.debtor_no')
                  ->leftJoin('customer_branches as cb', 'cb.id', '=', 'cb_min.min_id')
                  ->leftJoin('sales_areas as sa',       'sa.id', '=', 'cb.sales_area_id');
                return $q->selectRaw('COALESCE(CAST(sa.id AS CHAR),"0") AS key_id,
                    COALESCE(sa.area_name,"Unassigned") AS key_name,
                    SUM(sii.line_total) AS amount, SUM(sii.qty) AS qty, COUNT(DISTINCT si.id) AS invoices')
                    ->groupBy('sa.id','sa.area_name')->get()->keyBy('key_id');
            }
            return $q->selectRaw('si.debtor_no AS key_id, COALESCE(c.name, si.debtor_no) AS key_name,
                SUM(sii.line_total) AS amount, SUM(sii.qty) AS qty, COUNT(DISTINCT si.id) AS invoices')
                ->groupBy('si.debtor_no','c.name')->get()->keyBy('key_id');
        };

        $p1 = $fetch($p1From, $p1To);
        $p2 = $fetch($p2From, $p2To);

        $keys = $p1->keys()->merge($p2->keys())->unique();
        $rows = $keys->map(function ($key) use ($p1, $p2) {
            $a1 = (float)($p1[$key]->amount ?? 0);
            $a2 = (float)($p2[$key]->amount ?? 0);
            return [
                'key_id'    => $key,
                'key_name'  => $p1[$key]->key_name ?? $p2[$key]->key_name ?? (string)$key,
                'p1_amount' => round($a1, 2),
                'p2_amount' => round($a2, 2),
                'variance'  => round($a2 - $a1, 2),
                'pct_change'=> $a1 > 0 ? round(($a2 - $a1) / $a1 * 100, 1) : ($a2 > 0 ? 100.0 : 0.0),
                'p1_qty'    => (float)($p1[$key]->qty ?? 0),
                'p2_qty'    => (float)($p2[$key]->qty ?? 0),
            ];
        })->sortByDesc('p2_amount')->values();

        return ApiResponse::success([
            'rows'    => $rows,
            'totals'  => [
                'p1_amount' => round((float)$rows->sum('p1_amount'), 2),
                'p2_amount' => round((float)$rows->sum('p2_amount'), 2),
                'variance'  => round((float)$rows->sum('variance'), 2),
            ],
            'p1_from' => $p1From, 'p1_to' => $p1To,
            'p2_from' => $p2From, 'p2_to' => $p2To,
            'group_by' => $groupBy,
        ], 'Sales variance loaded');
    }

    // ── 14. Recurring Invoice Summary ─────────────────────────────────────────
    public function recurringInvoiceSummary(Request $r): JsonResponse
    {
        $from      = $r->get('date_from', now()->subMonths(6)->startOfMonth()->toDateString());
        $to        = $r->get('date_to',   now()->toDateString());
        $minMonths = max(1, (int)$r->get('min_months', 2));

        $q = DB::table('sales_invoices as si')
            ->join('sales_invoice_items as sii', 'sii.inv_id',  '=', 'si.id')
            ->leftJoin('customers as c',         'c.debtor_no', '=', 'si.debtor_no')
            ->where('si.status', 'placed')
            ->whereBetween('si.invoice_date', [$from, $to]);

        if ($r->filled('debtor_no'))   $q->where('si.debtor_no',   $r->get('debtor_no'));
        if ($r->filled('location_id')) $q->where('si.location_id', (int)$r->get('location_id'));

        $rows = $q->selectRaw("
            si.debtor_no,
            COALESCE(c.name, si.debtor_no)                        AS customer_name,
            COUNT(DISTINCT DATE_FORMAT(si.invoice_date,'%Y-%m'))  AS active_months,
            COUNT(DISTINCT si.id)                                 AS invoice_count,
            SUM(sii.line_total)                                   AS total_amount,
            MIN(si.invoice_date)                                  AS first_invoice,
            MAX(si.invoice_date)                                  AS last_invoice,
            SUM(sii.line_total)/NULLIF(COUNT(DISTINCT si.id),0)  AS avg_invoice_value
        ")
        ->groupBy('si.debtor_no', 'c.name')
        ->havingRaw('active_months >= ?', [$minMonths])
        ->orderByDesc('active_months')
        ->orderByDesc('total_amount')
        ->get();

        return ApiResponse::success([
            'rows'   => $rows,
            'totals' => [
                'amount'    => round((float)$rows->sum('total_amount'), 2),
                'invoices'  => $rows->sum('invoice_count'),
                'customers' => $rows->count(),
                'avg_monthly' => $rows->count() > 0
                    ? round((float)$rows->sum('total_amount') / max(1, (float)$rows->avg('active_months')), 2) : 0,
            ],
            'from' => $from, 'to' => $to,
        ], 'Recurring invoice summary loaded');
    }

    // ── CSV Export ────────────────────────────────────────────────────────────
    public function export(Request $r)
    {
        $report = $r->get('report', '');
        $name   = "{$report}-" . now()->format('Ymd-His') . '.csv';
        $title  = ucwords(str_replace('-', ' ', $report));

        [$headers, $rows] = match ($report) {
            'sales-summary' => [
                ['Period','Invoices','Customers','Total Qty','Total Amount','Avg Invoice'],
                $this->salesSummary($r)->getData(true)['data']['rows'],
            ],
            'sales-by-customer' => [
                ['Debtor No','Customer','Invoices','Total Qty','Revenue','Avg Invoice','Last Invoice','Share %'],
                $this->salesByCustomer($r)->getData(true)['data']['rows'],
            ],
            'sales-by-item' => [
                ['Stock ID','Description','Category','Invoices','Total Qty','Revenue','Avg Price','Last Invoice','Share %'],
                $this->salesByItem($r)->getData(true)['data']['rows'],
            ],
            'sales-by-salesperson' => [
                ['Salesperson','Customers','Invoices','Total Qty','Revenue','Last Invoice','Share %'],
                $this->salesBySalesperson($r)->getData(true)['data']['rows'],
            ],
            'sales-by-area' => [
                ['Area','Customers','Invoices','Total Qty','Revenue','Last Invoice','Share %'],
                $this->salesByArea($r)->getData(true)['data']['rows'],
            ],
            'customer-aged' => [
                ['Customer','Balance','Current','1-30 Days','31-60 Days','61-90 Days','90+ Days','Invoices'],
                $this->customerAgedAnalysis($r)->getData(true)['data']['rows'],
            ],
            'customer-statement' => [
                ['Date','Ref','Type','Debit','Credit','Balance'],
                $this->customerStatement($r)->getData(true)['data']['lines'],
            ],
            'outstanding-invoices' => [
                ['Invoice#','Customer','Invoice Date','Due Date','Amount','Paid','Balance','Days Overdue'],
                $this->outstandingInvoices($r)->getData(true)['data']['rows'],
            ],
            'price-list' => [
                ['Stock ID','Description','Category','Sales Type','Currency','Price'],
                $this->priceList($r)->getData(true)['data']['rows'],
            ],
            'delivery-status' => [
                ['DN#','Customer','Date','Amount','Status','Location','Invoice#','Deliver To'],
                $this->deliveryStatus($r)->getData(true)['data']['rows'],
            ],
            'credit-notes' => [
                ['CN#','Customer','Date','Type','Amount','Invoice#','Reason'],
                $this->creditNotes($r)->getData(true)['data']['rows'],
            ],
            'top-customers' => [
                ['Rank','Customer','Invoices','Total Qty','Revenue','First Invoice','Last Invoice','Share %'],
                $this->topCustomers($r)->getData(true)['data']['rows'],
            ],
            'sales-variance' => [
                ['Name','Period 1','Period 2','Variance','% Change'],
                $this->salesVariance($r)->getData(true)['data']['rows'],
            ],
            'recurring-invoice' => [
                ['Customer','Active Months','Invoices','Total Amount','Avg Invoice','First Invoice','Last Invoice'],
                $this->recurringInvoiceSummary($r)->getData(true)['data']['rows'],
            ],
            default => [[], []],
        };

        return response()->stream(function () use ($rows, $headers, $title) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ["Sales Report — {$title}"]);
            fputcsv($out, ["Generated: " . now()->format('d/m/Y H:i')]);
            fputcsv($out, []);
            fputcsv($out, $headers);
            foreach ((array)$rows as $row) {
                fputcsv($out, array_values((array)$row));
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
