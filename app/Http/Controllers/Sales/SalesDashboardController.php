<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SalesDashboardController extends Controller
{
    public function kpis(Request $request): JsonResponse
    {
        $from         = $request->get('date_from', now()->toDateString());
        $to           = $request->get('date_to',   now()->toDateString());
        $locationId   = $request->get('location_id');
        $dimensionId  = $request->get('dimension_id');
        $dimension2Id = $request->get('dimension2_id');

        $cacheKey = "dashboard_kpis:{$from}:{$to}:{$locationId}:{$dimensionId}:{$dimension2Id}";

        $data = Cache::remember($cacheKey, 60, function () use (
            $from, $to, $locationId, $dimensionId, $dimension2Id
        ) {
            $days     = (int) now()->parse($from)->diffInDays(now()->parse($to)) + 1;
            $prevTo   = now()->parse($from)->subDay()->toDateString();
            $prevFrom = now()->parse($prevTo)->subDays($days - 1)->toDateString();
            $ytdFrom  = now()->parse($from)->startOfYear()->toDateString();
            $mtdFrom  = now()->parse($from)->startOfMonth()->toDateString();
            // Widest range needed to cover all periods in one scan
            $wideFrom = min($prevFrom, $ytdFrom);

            $pct = fn($cur, $prev) => $prev != 0 ? round((($cur - $prev) / abs($prev)) * 100, 1) : null;

            // ── Q1: Invoice aggregates — all 4 periods in one query ──────────────
            // (was 4 separate queries)
            $invAgg = DB::table('sales_invoices')
                ->whereNotIn('status', ['cancelled', 'draft'])
                ->where('invoice_date', '>=', $wideFrom)
                ->where('invoice_date', '<=', $to)
                ->when($locationId,   fn($q) => $q->where('location_id',   $locationId))
                ->when($dimensionId,  fn($q) => $q->where('dimension_id',  $dimensionId))
                ->when($dimension2Id, fn($q) => $q->where('dimension2_id', $dimension2Id))
                ->selectRaw("
                    COALESCE(SUM(CASE WHEN invoice_date >= ? AND invoice_date <= ? THEN amount_total END), 0) as period_total,
                    SUM(CASE WHEN invoice_date >= ? AND invoice_date <= ? THEN 1 ELSE 0 END) as period_count,
                    COALESCE(SUM(CASE WHEN invoice_date >= ? AND invoice_date <= ? THEN amount_total END), 0) as prev_total,
                    COALESCE(SUM(CASE WHEN invoice_date >= ? THEN amount_total END), 0) as ytd_total,
                    COALESCE(SUM(CASE WHEN invoice_date >= ? THEN amount_total END), 0) as mtd_total
                ", [$from, $to, $from, $to, $prevFrom, $prevTo, $ytdFrom, $mtdFrom])
                ->first();

            // ── Q2: Outstanding balance ──────────────────────────────────────────
            $outstanding = DB::table('sales_invoices as i')
                ->leftJoin(DB::raw('(SELECT inv_id, SUM(amount) as alloc FROM debtor_allocations GROUP BY inv_id) a'), 'a.inv_id', '=', 'i.id')
                ->whereNotIn('i.status', ['cancelled', 'draft'])
                ->selectRaw('COALESCE(SUM(i.amount_total - IFNULL(a.alloc,0)),0) as outstanding')
                ->value('outstanding');

            // ── Q2b: Paid / open invoice counts (all-time) + settled-today ───────
            $paidAgg = DB::table('sales_invoices as i')
                ->leftJoin(DB::raw("(SELECT inv_id, SUM(amount) as alloc,
                        MAX(CASE WHEN allocated_date = CURDATE() THEN 1 ELSE 0 END) as settled_today
                    FROM debtor_allocations GROUP BY inv_id) a"), 'a.inv_id', '=', 'i.id')
                ->whereNotIn('i.status', ['cancelled', 'draft'])
                ->selectRaw("
                    SUM(CASE WHEN i.amount_total - IFNULL(a.alloc,0) <= 0.005 THEN 1 ELSE 0 END) as paid_count,
                    SUM(CASE WHEN i.amount_total - IFNULL(a.alloc,0) <= 0.005 AND a.settled_today = 1 THEN 1 ELSE 0 END) as paid_today_count,
                    SUM(CASE WHEN i.amount_total - IFNULL(a.alloc,0) > 0.005 THEN 1 ELSE 0 END) as open_count
                ")
                ->first();

            // ── Q2c: Customers — active count + new this week ────────────────────
            $customerAgg = DB::table('customers')
                ->selectRaw('
                    SUM(CASE WHEN inactive = 0 THEN 1 ELSE 0 END) as active_count,
                    SUM(CASE WHEN inactive = 0 AND created_at >= ? THEN 1 ELSE 0 END) as new_this_week_count
                ', [now()->subDays(7)])
                ->first();

            // ── Q3: GL aggregates — COGS + OpEx for all periods in one query ─────
            // (was 9 separate queries)
            $gl = DB::table('gld_transactions')
                ->where('tran_date', '>=', $wideFrom)
                ->where('tran_date', '<=', $to)
                ->whereRaw("(account_code LIKE '%501010%' OR account_code IN ('BAD_DEBT','BANK_CHARGES','DISCOUNT'))")
                ->selectRaw("
                    COALESCE(SUM(CASE WHEN tran_date >= ? AND tran_date <= ? AND account_code LIKE '%501010%' THEN amount END), 0) as cogs,
                    COALESCE(SUM(CASE WHEN tran_date >= ? AND tran_date <= ? AND account_code LIKE '%501010%' THEN amount END), 0) as prev_cogs,
                    COALESCE(SUM(CASE WHEN tran_date >= ? AND account_code LIKE '%501010%' THEN amount END), 0) as ytd_cogs,
                    COALESCE(SUM(CASE WHEN tran_date >= ? AND account_code LIKE '%501010%' THEN amount END), 0) as mtd_cogs,
                    COALESCE(SUM(CASE WHEN tran_date >= ? AND tran_date <= ? AND account_code IN ('BAD_DEBT','BANK_CHARGES','DISCOUNT') AND amount > 0 THEN amount END), 0) as opex,
                    COALESCE(SUM(CASE WHEN tran_date >= ? AND tran_date <= ? AND account_code IN ('BAD_DEBT','BANK_CHARGES','DISCOUNT') AND amount > 0 THEN amount END), 0) as prev_opex,
                    COALESCE(SUM(CASE WHEN tran_date >= ? AND account_code IN ('BAD_DEBT','BANK_CHARGES','DISCOUNT') AND amount > 0 THEN amount END), 0) as ytd_opex,
                    COALESCE(SUM(CASE WHEN tran_date >= ? AND account_code IN ('BAD_DEBT','BANK_CHARGES','DISCOUNT') AND amount > 0 THEN amount END), 0) as mtd_opex
                ", [
                    $from, $to,
                    $prevFrom, $prevTo,
                    $ytdFrom,
                    $mtdFrom,
                    $from, $to,
                    $prevFrom, $prevTo,
                    $ytdFrom,
                    $mtdFrom,
                ])
                ->first();
                    // echo json_encode($gl );
            // ── Q4: Payments ─────────────────────────────────────────────────────
            $payments = DB::table('customer_payments')
                ->whereBetween('payment_date', [$from, $to])
                ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount),0) as total, COALESCE(SUM(unallocated_amount),0) as unallocated')
                ->first();

            $paymentTypes = DB::table('customer_payments')
                ->whereBetween('payment_date', [$from, $to])
                ->selectRaw('payment_type, COUNT(*) as count, COALESCE(SUM(amount),0) as total')
                ->groupBy('payment_type')
                ->get();

            // ── Q5: M-Pesa ───────────────────────────────────────────────────────
            $mpesa = DB::table('mpesa_payments')
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN transfer_user != '' AND transfer_user IS NOT NULL THEN 1 ELSE 0 END) as transferred,
                    SUM(CASE WHEN transfer_user = '' OR transfer_user IS NULL THEN 1 ELSE 0 END) as pending,
                    COALESCE(SUM(TransAmount),0) as total_amount,
                    COALESCE(SUM(CASE WHEN transfer_user = '' OR transfer_user IS NULL THEN TransAmount ELSE 0 END),0) as pending_amount
                ")
                ->first();

            // ── Q6: Deposits + customers + allocations in parallel selects ────────
            $deposits      = DB::table('customer_deposits')
                ->selectRaw('COUNT(*) as count, COALESCE(SUM(deposit_amount),0) as total, COALESCE(SUM(unallocated_amount),0) as unallocated')
                ->first();
            $allocations   = DB::table('debtor_allocations')
                ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount),0) as total')
                ->first();

            // ── Q7: Top customers + recent payments ──────────────────────────────
            $topCustomers = DB::table('sales_invoices as i')
                ->join('customers as c', 'c.debtor_no', '=', 'i.debtor_no')
                ->whereBetween('i.invoice_date', [$from, $to])
                ->whereNotIn('i.status', ['cancelled', 'draft'])
                ->when($locationId,   fn($q) => $q->where('i.location_id',   $locationId))
                ->when($dimensionId,  fn($q) => $q->where('i.dimension_id',  $dimensionId))
                ->when($dimension2Id, fn($q) => $q->where('i.dimension2_id', $dimension2Id))
                ->selectRaw('c.name, i.debtor_no, COUNT(*) as inv_count, COALESCE(SUM(i.amount_total),0) as total')
                ->groupBy('i.debtor_no', 'c.name')
                ->orderByDesc('total')
                ->limit(5)
                ->get();

            $recentPayments = DB::table('customer_payments as p')
                ->join('customers as c', 'c.debtor_no', '=', 'p.debtor_no')
                ->orderByDesc('p.created_at')
                ->limit(8)
                ->get(['p.payment_no', 'p.payment_date', 'p.amount', 'p.payment_type', 'p.unallocated_amount', 'c.name', 'p.debtor_no']);

            // ── Q8: Category sales — all 4 periods in one query ──────────────────
            // (was 4 separate queries)
            $catRows = DB::table('sales_invoice_items as li')
                ->join('sales_invoices as i', 'i.id', '=', 'li.inv_id')
                ->join('items as it', 'it.stock_id', '=', 'li.stock_id')
                ->leftJoin('item_categories as c', 'c.id', '=', 'it.category_id')
                ->whereNotIn('i.status', ['cancelled', 'draft'])
                ->where('i.invoice_date', '>=', $wideFrom)
                ->where('i.invoice_date', '<=', $to)
                ->when($locationId,   fn($q) => $q->where('i.location_id',   $locationId))
                ->when($dimensionId,  fn($q) => $q->where('i.dimension_id',  $dimensionId))
                ->when($dimension2Id, fn($q) => $q->where('i.dimension2_id', $dimension2Id))
                ->selectRaw("
                    COALESCE(c.name, 'Other') as category,
                    COALESCE(SUM(CASE WHEN i.invoice_date >= ? AND i.invoice_date <= ? THEN li.line_total END), 0) as period,
                    COALESCE(SUM(CASE WHEN i.invoice_date >= ? AND i.invoice_date <= ? THEN li.line_total END), 0) as prev,
                    COALESCE(SUM(CASE WHEN i.invoice_date >= ? THEN li.line_total END), 0) as ytd,
                    COALESCE(SUM(CASE WHEN i.invoice_date >= ? THEN li.line_total END), 0) as mtd
                ", [$from, $to, $prevFrom, $prevTo, $ytdFrom, $mtdFrom])
                ->groupBy('c.id', 'c.name')
                ->orderByDesc('period')
                ->get();

            $categoryRows = $catRows->map(fn($r) => [
                'category'   => $r->category,
                'period'     => round((float) $r->period, 2),
                'mtd'        => round((float) $r->mtd, 2),
                'ytd'        => round((float) $r->ytd, 2),
                'change_pct' => $pct($r->period, $r->prev),
            ])->values();

            // ── Q9: Store performance — current + prev in one query ───────────────
            // (was 2 separate queries)
            $storeRows = DB::table('sales_invoices as i')
                ->leftJoin('inventory_locations as l', 'l.id', '=', 'i.location_id')
                ->join('sales_invoice_items as li', 'li.inv_id', '=', 'i.id')
                ->whereNotIn('i.status', ['cancelled', 'draft'])
                ->where('i.invoice_date', '>=', $prevFrom)
                ->where('i.invoice_date', '<=', $to)
                ->when($locationId,   fn($q) => $q->where('i.location_id',   $locationId))
                ->when($dimensionId,  fn($q) => $q->where('i.dimension_id',  $dimensionId))
                ->when($dimension2Id, fn($q) => $q->where('i.dimension2_id', $dimension2Id))
                ->selectRaw("
                    i.location_id,
                    COALESCE(l.name, 'Unknown') as loc_name,
                    COALESCE(l.code, 'UNK') as loc_code,
                    COALESCE(l.type, '') as loc_type,
                    SUM(CASE WHEN i.invoice_date >= ? AND i.invoice_date <= ? THEN 1 ELSE 0 END) as inv_count,
                    COALESCE(SUM(CASE WHEN i.invoice_date >= ? AND i.invoice_date <= ? THEN li.line_total END), 0) as revenue,
                    COALESCE(SUM(CASE WHEN i.invoice_date >= ? AND i.invoice_date <= ? THEN li.qty * li.standard_cost END), 0) as cogs,
                    COALESCE(SUM(CASE WHEN i.invoice_date >= ? AND i.invoice_date <= ? THEN li.line_total END), 0) as prev_revenue
                ", [$from, $to, $from, $to, $from, $to, $prevFrom, $prevTo])
                ->groupBy('i.location_id', 'l.name', 'l.code', 'l.type')
                ->orderByDesc('revenue')
                ->get()
                ->map(function ($row) use ($pct) {
                    $revenue  = round((float) $row->revenue, 2);
                    $cogs     = round((float) $row->cogs, 2);
                    $gp       = round($revenue - $cogs, 2);
                    $prevRev  = (float) $row->prev_revenue;
                    return [
                        'location_id'  => $row->location_id,
                        'name'         => $row->loc_name,
                        'code'         => $row->loc_code,
                        'type'         => $row->loc_type,
                        'inv_count'    => (int) $row->inv_count,
                        'revenue'      => $revenue,
                        'cogs'         =>  $cogs,
                        'gross_profit' => $gp,
                        'margin'       => $revenue > 0 ? round(($gp / $revenue) * 100, 1) : 0,
                        'trend_pct'    => $pct($revenue, $prevRev),
                    ];
                })->values();

            // ── Derived P&L values (pure PHP — no extra queries) ─────────────────
            $revenue       = round((float) $invAgg->period_total, 2);
            $prevRevenue   = round((float) $invAgg->prev_total, 2);
            $ytdRevenue    = round((float) $invAgg->ytd_total, 2);
            $mtdRevenue    = round((float) $invAgg->mtd_total, 2);
            $cogs          = round((float) $gl->cogs, 2);
            $prevCogs      = round((float) $gl->prev_cogs, 2);
            $ytdCogs       = round((float) $gl->ytd_cogs, 2);
            $mtdCogs       = round((float) $gl->mtd_cogs, 2);
            $opExpenses    = round((float) $gl->opex, 2);
            $prevOpExpenses= round((float) $gl->prev_opex, 2);
            $ytdOpExpenses = round((float) $gl->ytd_opex, 2);
            $mtdOpExpenses = round((float) $gl->mtd_opex, 2);
            $grossProfit   = round($revenue - $cogs, 2);
            $grossMargin   = $revenue > 0 ? round(($grossProfit / $revenue) * 100, 1) : 0;
            $netProfit     = round($grossProfit - $opExpenses, 2);
            $netMargin     = $revenue > 0 ? round(($netProfit / $revenue) * 100, 1) : 0;
            $prevNetProfit = round(($prevRevenue - $prevCogs) - $prevOpExpenses, 2);
            $ytdNetProfit  = round(($ytdRevenue  - $ytdCogs)  - $ytdOpExpenses,  2);
            $mtdNetProfit  = round(($mtdRevenue  - $mtdCogs)  - $mtdOpExpenses,  2);
            $mtdNetMargin  = $mtdRevenue > 0 ? round(($mtdNetProfit / $mtdRevenue) * 100, 1) : 0;

            return [
                'period'          => ['from' => $from, 'to' => $to, 'prev_from' => $prevFrom, 'prev_to' => $prevTo],
                'invoices'        => [
                    'count'            => (int) $invAgg->period_count,
                    'total'            => $revenue,
                    'outstanding'      => round((float) $outstanding, 2),
                    'paid_count'       => (int) $paidAgg->paid_count,
                    'paid_today_count' => (int) $paidAgg->paid_today_count,
                    'open_count'       => (int) $paidAgg->open_count,
                ],
                'payments'        => [
                    'count'       => (int) $payments->count,
                    'total'       => (float) $payments->total,
                    'unallocated' => (float) $payments->unallocated,
                    'by_type'     => $paymentTypes,
                ],
                'mpesa'           => [
                    'total'          => (int) $mpesa->total,
                    'transferred'    => (int) $mpesa->transferred,
                    'pending'        => (int) $mpesa->pending,
                    'total_amount'   => (float) $mpesa->total_amount,
                    'pending_amount' => (float) $mpesa->pending_amount,
                ],
                'deposits'        => [
                    'count'       => (int) $deposits->count,
                    'total'       => (float) $deposits->total,
                    'unallocated' => (float) $deposits->unallocated,
                ],
                'customers'              => (int) $customerAgg->active_count,
                'customers_new_this_week'=> (int) $customerAgg->new_this_week_count,
                'allocations'     => [
                    'count' => (int) $allocations->count,
                    'total' => (float) $allocations->total,
                ],
                'pnl'             => [
                    'revenue'            => $revenue,
                    'prev_revenue'       => $prevRevenue,
                    'mtd_revenue'        => $mtdRevenue,
                    'ytd_revenue'        => $ytdRevenue,
                    'cogs'               => $cogs,
                    'prev_cogs'          => $prevCogs,
                    'ytd_cogs'           => $ytdCogs,
                    'gross_profit'       => $grossProfit,
                    'gross_margin'       => $grossMargin,
                    'operating_expenses' => $opExpenses,
                    'net_profit'         => $netProfit,
                    'prev_net_profit'    => $prevNetProfit,
                    'mtd_net_profit'     => $mtdNetProfit,
                    'mtd_net_margin'     => $mtdNetMargin,
                    'ytd_net_profit'     => $ytdNetProfit,
                    'net_margin'         => $netMargin,
                    'revenue_change_pct' => $pct($revenue, $prevRevenue),
                    'net_change_pct'     => $pct($netProfit, $prevNetProfit),
                ],
                'category_sales'  => $categoryRows,
                'top_customers'   => $topCustomers,
                'store_performance'=> $storeRows,
            ];
        });

        return ApiResponse::success($data, 'Sales dashboard KPIs');
    }

    public function widgetData(Request $request): JsonResponse
    {
        $from = $request->get('date_from', now()->toDateString());
        $to   = $request->get('date_to',   now()->toDateString());
        $today = now()->toDateString();

        // ── Aged debtors: outstanding placed invoices bucketed by days overdue ─
        $invoices = DB::table('sales_invoices as i')
            ->leftJoin(DB::raw('(SELECT inv_id, SUM(amount) as alloc FROM debtor_allocations GROUP BY inv_id) a'), 'a.inv_id', '=', 'i.id')
            ->where('i.status', 'placed')
            ->whereRaw('COALESCE(i.amount_total - IFNULL(a.alloc,0), i.amount_total) > 0')
            ->selectRaw('
                COALESCE(i.due_date, i.invoice_date) as due,
                COALESCE(i.amount_total - IFNULL(a.alloc,0), i.amount_total) as balance
            ')
            ->get();

        $aged = ['current' => 0, 'd30' => 0, 'd60' => 0, 'd90' => 0, 'd90plus' => 0];
        foreach ($invoices as $inv) {
            $days = (int) now()->parse($today)->diffInDays(now()->parse($inv->due), false) * -1;
            $bal  = (float) $inv->balance;
            if ($days <= 0)       $aged['current'] += $bal;
            elseif ($days <= 30)  $aged['d30']     += $bal;
            elseif ($days <= 60)  $aged['d60']     += $bal;
            elseif ($days <= 90)  $aged['d90']     += $bal;
            else                  $aged['d90plus']  += $bal;
        }
        $agedResult = array_map(fn($v) => round($v, 2), $aged);
        $agedResult['total'] = round(array_sum($aged), 2);

        // ── Stock alerts: items below reorder level ────────────────────────────
        // items PK is stock_id (no id column); item_reorder_levels also has stock_id
        $stockAlerts = DB::table('item_reorder_levels as rl')
            ->join('items as it', 'it.stock_id', '=', 'rl.stock_id')
            ->join('inventory_locations as il', 'il.id', '=', 'rl.location_id')
            ->joinSub(
                DB::table('stock_movements')
                    ->select('stock_id', 'loc_code', DB::raw('SUM(qty) as balance'))
                    ->groupBy('stock_id', 'loc_code'),
                'bal',
                fn($j) => $j->on('bal.stock_id', '=', 'rl.stock_id')
                             ->on('bal.loc_code',  '=', 'il.code')
            )
            ->where('rl.reorder_level', '>', 0)
            ->whereRaw('bal.balance < rl.reorder_level')
            ->select('it.stock_id', 'it.description', 'il.name as location',
                     DB::raw('bal.balance as qty_on_hand'), 'rl.reorder_level')
            ->orderByRaw('(rl.reorder_level - bal.balance) DESC')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'stock_id'      => $r->stock_id,
                'description'   => $r->description,
                'location'      => $r->location,
                'qty_on_hand'   => round((float) $r->qty_on_hand, 2),
                'reorder_level' => round((float) $r->reorder_level, 2),
                'shortfall'     => round(max(0, (float) $r->reorder_level - (float) $r->qty_on_hand), 2),
            ]);

        // ── Unallocated payments ───────────────────────────────────────────────
        $unallocPay = DB::table('customer_payments')
            ->where('unallocated_amount', '>', 0)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(unallocated_amount),0) as amount')
            ->first();

        // ── eTIMS compliance: placed invoices not yet stamped ─────────────────
        $etimsTotal   = DB::table('sales_invoices')->where('status', 'placed')->count();
        $etimsStamped = DB::table('sales_invoices')
            ->where('status', 'placed')
            ->whereNotNull('etims_stamped_at')
            ->count();
        $etimsUnstamped = max(0, $etimsTotal - $etimsStamped);

        // ── Purchase orders pending (type=po, awaiting action) ────────────────
        $poStatuses = DB::table('purchase_orders')
            ->where('type', 'po')
            ->whereNotIn('status', ['received', 'cancelled'])
            ->selectRaw("status, COUNT(*) as cnt, COALESCE(SUM(amount_total),0) as total")
            ->groupBy('status')
            ->get()
            ->keyBy('status')
            ->map(fn($r) => ['count' => (int) $r->cnt, 'total' => round((float) $r->total, 2)]);

        $pendingPoCount = $poStatuses->sum('count');
        $pendingPoTotal = round($poStatuses->sum('total'), 2);

        // ── Revenue trend: daily invoice totals — always last 30 days ─────────
        $trendFrom = now()->parse($to)->subDays(29)->toDateString();
        $trend = DB::table('sales_invoices')
            ->whereBetween('invoice_date', [$trendFrom, $to])
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->selectRaw('invoice_date as date, COALESCE(SUM(amount_total),0) as revenue')
            ->groupBy('invoice_date')
            ->orderBy('invoice_date')
            ->get()
            ->map(fn($r) => ['date' => $r->date, 'revenue' => round((float) $r->revenue, 2)]);

        return ApiResponse::success([
            'aged_debtors'       => $agedResult,
            'stock_alerts'       => ['count' => $stockAlerts->count(), 'items' => $stockAlerts],
            'unallocated_payments'=> ['count' => (int) $unallocPay->count, 'amount' => round((float) $unallocPay->amount, 2)],
            'etims_compliance'   => ['total' => $etimsTotal, 'stamped' => $etimsStamped, 'unstamped' => $etimsUnstamped],
            'pending_orders'     => ['count' => $pendingPoCount, 'total' => $pendingPoTotal, 'by_status' => $poStatuses],
            'revenue_trend'      => $trend,
        ], 'Dashboard widget data');
    }

    public function storeItems(Request $request): JsonResponse
    {
        $locationId    = $request->get('location_id');
        $nullLocation  = $request->boolean('null_location'); // true = show invoices with no location
        $from = $request->get('date_from', now()->toDateString());
        $to   = $request->get('date_to',   now()->toDateString());

        $items = DB::table('sales_invoice_items as li')
            ->join('sales_invoices as i', 'i.id', '=', 'li.inv_id')
            ->leftJoin('items as it', 'it.stock_id', '=', 'li.stock_id')
            ->leftJoin('item_categories as c', 'c.id', '=', 'it.category_id')
            ->whereNotIn('i.status', ['cancelled', 'draft'])
            ->whereBetween('i.invoice_date', [$from, $to])
            ->when($nullLocation, fn($q) => $q->whereNull('i.location_id'))
            ->when(!$nullLocation && $locationId !== null && $locationId !== '', fn($q) => $q->where('i.location_id', $locationId))
            ->selectRaw("
                li.stock_id,
                li.description,
                COALESCE(c.name, 'Uncategorised') as category,
                SUM(li.qty) as qty_sold,
                li.unit,
                COALESCE(AVG(li.price), 0) as avg_price,
                COALESCE(SUM(li.line_total), 0) as revenue,
                COALESCE(SUM(li.qty * li.standard_cost), 0) as cogs
            ")
            ->groupBy('li.stock_id', 'li.description', 'c.name', 'li.unit')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn($r) => [
                'stock_id'    => $r->stock_id,
                'description' => $r->description,
                'category'    => $r->category,
                'qty_sold'    => round((float) $r->qty_sold, 2),
                'unit'        => $r->unit,
                'avg_price'   => round((float) $r->avg_price, 2),
                'revenue'     => round((float) $r->revenue, 2),
                'cogs'        => round((float) $r->cogs, 2),
                'gross_profit'=> round((float) $r->revenue - (float) $r->cogs, 2),
                'margin'      => $r->revenue > 0 ? round((($r->revenue - $r->cogs) / $r->revenue) * 100, 1) : 0,
            ]);

        if ($nullLocation) {
            $location = (object) ['name' => 'Unknown Location', 'code' => 'UNK', 'type' => ''];
        } elseif ($locationId) {
            $location = DB::table('inventory_locations')->where('id', $locationId)->first(['name', 'code', 'type'])
                ?? (object) ['name' => 'Location #' . $locationId, 'code' => '', 'type' => ''];
        } else {
            $location = (object) ['name' => 'All Locations', 'code' => 'ALL', 'type' => ''];
        }

        return ApiResponse::success([
            'location' => $location,
            'period'   => ['from' => $from, 'to' => $to],
            'items'    => $items,
            'totals'   => [
                'revenue'     => round($items->sum('revenue'), 2),
                'cogs'        => round($items->sum('cogs'), 2),
                'gross_profit'=> round($items->sum('gross_profit'), 2),
                'qty_sold'    => round($items->sum('qty_sold'), 2),
            ],
        ], 'Store items');
    }

    public function milkTraceability(Request $request): JsonResponse
    {
        $from       = $request->get('date_from', now()->toDateString());
        $to         = $request->get('date_to',   now()->toDateString());
        $locationId = $request->get('location_id');

        $rawMilkStockId = DB::table('items')
            ->where(fn ($q) => $q
                ->where('long_description', 'like', '%RAW MILK%')
                ->orWhere('description', 'like', '%RAW MILK%'))
            ->value('stock_id') ?? 'MILK-RAW';

        // Farmers — raw collection from individual farmer deliveries, for the
        // selected period. Accepted only — a delivery that failed QC never
        // entered inventory or got paid for (see MilkPurchaseApprovalService),
        // so folding it into "supplied" here would overstate genuine intake.
        // It's surfaced separately below instead, for visibility only.
        // No location filter here: milk_purchases has no location_id column
        // (only route_id/grader_id, and milk_routes.location_id is mostly
        // unset — see the credit-limit fix) — silently filtering on a mostly-
        // NULL mapping would just make this tile go blank, not scope it.
        $farmers = (float) DB::table('milk_purchase_items')
            ->join('milk_purchases', 'milk_purchases.id', '=', 'milk_purchase_items.purchase_id')
            ->whereBetween('milk_purchases.invoice_date', [$from, $to])
            ->where('milk_purchase_items.quality_status', 'accepted')
            ->sum('milk_purchase_items.quantity');

        // Rejected — failed QC at collection, recorded for reporting/farmer
        // follow-up only. Never touches stock, GRN, or GL — this is purely
        // visibility so the office can see and follow up on it.
        $rejected = DB::table('milk_purchase_items')
            ->join('milk_purchases', 'milk_purchases.id', '=', 'milk_purchase_items.purchase_id')
            ->whereBetween('milk_purchases.invoice_date', [$from, $to])
            ->where('milk_purchase_items.quality_status', 'rejected')
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(milk_purchase_items.quantity), 0) as qty')
            ->first();

        // Sold — raw milk sold via placed sales invoices, for the selected period
        $soldGross = (float) DB::table('sales_invoice_items')
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_items.inv_id')
            ->whereBetween('sales_invoices.invoice_date', [$from, $to])
            ->where('sales_invoice_items.stock_id', $rawMilkStockId)
            ->where('sales_invoices.status', '!=', 'draft')
            ->when($locationId, fn ($q) => $q->where('sales_invoices.location_id', $locationId))
            ->sum('sales_invoice_items.qty');

        // Returned — raw milk physically returned via placed credit notes
        // (cn_type return/return_to_store only; discount/write_off credit
        // notes are pure financial adjustments with no stock movement, so
        // they're excluded here). Netted off "sold" so the traceability
        // figure reflects milk that actually stayed sold, not gross invoiced.
        $returned = (float) DB::table('credit_note_items as cni')
            ->join('credit_notes as cn', 'cn.id', '=', 'cni.cn_id')
            ->whereBetween('cn.cn_date', [$from, $to])
            ->where('cni.stock_id', $rawMilkStockId)
            ->where('cn.status', 'placed')
            ->whereIn('cn.cn_type', ['return', 'return_to_store'])
            ->when($locationId, fn ($q) => $q->where('cn.location_id', $locationId))
            ->sum('cni.qty');

        $sold = max(0.0, $soldGross - $returned);

        // Stations — split by movement stage so the totals don't double-count
        // the same litres as they change hands: milk is first collected at a
        // grader/station (GRN receipt, type=25 — this is the same source data
        // as farmers_supplied) and MAY subsequently be transferred on to
        // another location (type=13, receiving side only — dispatches are
        // recorded as a negative qty at the source and excluded here). A
        // station's total on-hand can still differ from either figure (also
        // reflects what left via sales/consumption/dispatch and stock from
        // before $from) — these are period movement totals, not a balance.
        $stationsQuery = fn ($type) => DB::table('stock_movements as sm')
            ->join('inventory_locations as l', 'l.code', '=', 'sm.loc_code')
            ->where('sm.stock_id', $rawMilkStockId)
            ->where('sm.type', $type)
            ->where('sm.qty', '>', 0)
            ->whereBetween('sm.tran_date', [$from, $to])
            ->where('l.inactive', 0)
            ->whereNotIn('l.type', ['Vendor', 'SALES'])
            ->when($locationId, fn ($q) => $q->where('l.id', $locationId))
            ->groupBy('l.id', 'l.code', 'l.name', 'l.type')
            ->havingRaw('SUM(sm.qty) > 0.001')
            ->selectRaw('l.code, l.name, l.type, ROUND(SUM(sm.qty), 1) as qty')
            ->orderByDesc('qty')
            ->get();

        $stationsCollected   = $stationsQuery(25);
        $stationsTransferred = $stationsQuery(13);

        return ApiResponse::success([
            'period'                     => ['from' => $from, 'to' => $to],
            'farmers_supplied'           => round($farmers, 1),
            'sold'                       => round($sold, 1),
            'sold_gross'                 => round($soldGross, 1),
            'returned_qty'               => round($returned, 1),
            'stations_collected'         => $stationsCollected,
            'stations_collected_total'   => round($stationsCollected->sum('qty'), 1),
            'stations_transferred'       => $stationsTransferred,
            'stations_transferred_total' => round($stationsTransferred->sum('qty'), 1),
            // Visible for reporting/follow-up — never included in
            // farmers_supplied or stations_total, since rejected milk was
            // never accepted into the business.
            'rejected_qty'     => round((float) ($rejected->qty ?? 0), 1),
            'rejected_count'   => (int) ($rejected->cnt ?? 0),
        ], 'Milk traceability loaded');
    }

    public function graderCollections(Request $request): JsonResponse
    {
        $from = $request->get('date_from', now()->toDateString());
        $to   = $request->get('date_to',   now()->toDateString());

        $rows = DB::table('milk_grader_collections as gc')
            ->join('milk_purchases as mp', 'mp.id', '=', 'gc.purchase_id')
            ->leftJoin('milk_routes as mr', 'mr.id', '=', 'mp.route_id')
            ->whereBetween('gc.date_collected', [$from, $to])
            ->selectRaw("
                gc.location            AS grader_code,
                COALESCE(mr.route_name, 'Unknown') AS route_name,
                COALESCE(mr.route_code, '-')       AS route_code,
                COUNT(DISTINCT gc.purchase_id)     AS sessions,
                SUM(gc.quantity)                   AS total_qty,
                SUM(gc.quantity * gc.rate)         AS total_amount,
                AVG(gc.rate)                       AS avg_rate,
                MAX(gc.date_collected)             AS last_collection
            ")
            ->groupBy('gc.location', 'mr.route_name', 'mr.route_code')
            ->orderByDesc('total_qty')
            ->get()
            ->map(fn($r) => [
                'grader_code'     => $r->grader_code,
                'route_name'      => $r->route_name,
                'route_code'      => $r->route_code,
                'sessions'        => (int)   $r->sessions,
                'total_qty'       => round((float) $r->total_qty, 1),
                'total_amount'    => round((float) $r->total_amount, 2),
                'avg_rate'        => round((float) $r->avg_rate, 2),
                'last_collection' => $r->last_collection,
            ]);

        $grandQty    = $rows->sum('total_qty');
        $grandAmount = $rows->sum('total_amount');

        // Add share % of total qty per grader
        $rows = $rows->map(fn($r) => array_merge($r, [
            'pct' => $grandQty > 0 ? round($r['total_qty'] / $grandQty * 100, 1) : 0,
        ]));

        return ApiResponse::success([
            'period'  => ['from' => $from, 'to' => $to],
            'graders' => $rows->values(),
            'totals'  => [
                'qty'    => round($grandQty, 1),
                'amount' => round($grandAmount, 2),
            ],
        ], 'Grader collections loaded');
    }

    public function graderDetail(Request $request): JsonResponse
    {
        $graderCode = $request->get('grader_code', '');
        $from       = $request->get('date_from', now()->toDateString());
        $to         = $request->get('date_to',   now()->toDateString());

        // ── Farmers collected for this grader ───────────────────────────────
        // $farmers = DB::table('milk_grader_collections as gc')
        //     ->join('milk_purchases as mp',       'mp.id',  '=', 'gc.purchase_id')
        //     ->join('milk_purchase_items as mpi', 'mpi.purchase_id', '=', 'mp.id')
        //     ->Join('farmers as f',           'f.id',   '=', 'mpi.farmer_id')
        //     ->where('gc.location', $graderCode)
        //     ->whereBetween('gc.date_collected', [$from, $to])
        //     ->selectRaw("
        //         COALESCE(f.farmer_no,   'Unknown') AS farmer_no,
        //         COALESCE(f.full_name,   'Unknown') AS farmer_name,
        //         COALESCE(f.phone,       '')        AS phone,
        //         SUM(mpi.quantity)                  AS total_qty,
        //         SUM(mpi.total_price)               AS total_amount,
        //         AVG(mpi.unit_price)                AS avg_rate,
        //         COUNT(DISTINCT gc.date_collected)  AS sessions,
        //         MAX(gc.date_collected)             AS last_collection
        //     ")
        //     ->groupBy('gc.location', 'mpi.farmer_id','gc.date_collected')
        //     ->orderByDesc('total_qty')
        //     ->get();
        $farmers = DB::table(function ($query) {
        $query->from('milk_purchase_items')
            ->select('purchase_id', 'farmer_id')
            ->selectRaw('SUM(quantity)    AS total_qty')
            ->selectRaw('SUM(total_price) AS total_amount')
            ->selectRaw('AVG(unit_price)  AS avg_rate')
            ->groupBy('purchase_id', 'farmer_id');
    }, 'mpi_agg')
    ->join('milk_purchases as mp', 'mp.id', '=', 'mpi_agg.purchase_id')
    ->join('milk_grader_collections as gc', 'gc.purchase_id', '=', 'mp.id')
    ->join('farmers as f', 'f.id', '=', 'mpi_agg.farmer_id')
    ->where('gc.location', $graderCode)
    ->whereBetween('gc.date_collected', [$from, $to])
    ->selectRaw("
        COALESCE(f.farmer_no, 'Unknown') AS farmer_no,
        COALESCE(f.full_name, 'Unknown') AS farmer_name,
        COALESCE(f.phone, '')            AS phone,
        mpi_agg.total_qty,
        mpi_agg.total_amount,
        mpi_agg.avg_rate,
        COUNT(DISTINCT gc.date_collected) AS sessions,
        MAX(gc.date_collected)            AS last_collection
    ")
    ->groupBy(
        'mpi_agg.farmer_id',
        'f.farmer_no',
        'f.full_name',
        'f.phone',
        'mpi_agg.total_qty',
        'mpi_agg.total_amount',
        'mpi_agg.avg_rate'
    )
    ->orderByDesc('total_qty')
    ->get();
        // echo json_encode($farmers);
        $grandQty = $farmers->sum('total_qty');

        $farmers = $farmers->map(fn($r) => [
            'farmer_no'       => $r->farmer_no,
            'farmer_name'     => $r->farmer_name,
            'phone'           => $r->phone,
            'total_qty'       => round((float) $r->total_qty, 1),
            'total_amount'    => round((float) $r->total_amount, 2),
            'avg_rate'        => round((float) $r->avg_rate, 2),
            'sessions'        => (int) $r->sessions,
            'last_collection' => $r->last_collection,
            'pct'             => $grandQty > 0 ? round($r->total_qty / $grandQty * 100, 1) : 0,
        ]);

        // ── Daily breakdown ─────────────────────────────────────────────────
        $daily = DB::table('milk_grader_collections as gc')
            ->where('gc.location', $graderCode)
            ->whereBetween('gc.date_collected', [$from, $to])
            ->groupBy('gc.date_collected')
            ->selectRaw('
                gc.date_collected AS date,
                SUM(gc.quantity)  AS qty,
                AVG(gc.rate)      AS avg_rate,
                COUNT(*)          AS entries
            ')
            ->orderBy('gc.date_collected')
            ->get()
            ->map(fn($r) => [
                'date'     => $r->date,
                'qty'      => round((float) $r->qty, 1),
                'avg_rate' => round((float) $r->avg_rate, 2),
                'entries'  => (int) $r->entries,
            ]);

        // ── Summary ─────────────────────────────────────────────────────────
        $totalQty    = $farmers->sum('total_qty');
        $totalAmount = $farmers->sum('total_amount');
        $avgRate     = $totalQty > 0 ? round($totalAmount / $totalQty, 2) : 0;
        $topFarmer   = $farmers->first();

        return ApiResponse::success([
            'period'  => ['from' => $from, 'to' => $to],
            'summary' => [
                'grader_code'    => $graderCode,
                'total_qty'      => $totalQty,
                'total_amount'   => $totalAmount,
                'avg_rate'       => $avgRate,
                'unique_farmers' => $farmers->count(),
                'sessions'       => $daily->count(),
            ],
            'farmers' => $farmers->values(),
            'daily'   => $daily->values(),
            'top_farmer' => $topFarmer ? [
                'name' => $topFarmer['farmer_name'],
                'qty'  => $topFarmer['total_qty'],
                'pct'  => $topFarmer['pct'],
            ] : null,
        ], 'Grader detail loaded');
    }
}
