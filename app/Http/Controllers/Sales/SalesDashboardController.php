<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesDashboardController extends Controller
{
    public function kpis(Request $request): JsonResponse
    {
        $from        = $request->get('date_from', now()->startOfMonth()->toDateString());
        $to          = $request->get('date_to',   now()->toDateString());
        $locationId  = $request->get('location_id');
        $dimensionId = $request->get('dimension_id');
        $dimension2Id= $request->get('dimension2_id');

        // Previous period (same span, ending day before $from)
        $days      = (int) now()->parse($from)->diffInDays(now()->parse($to)) + 1;
        $prevTo    = now()->parse($from)->subDay()->toDateString();
        $prevFrom  = now()->parse($prevTo)->subDays($days - 1)->toDateString();
        $ytdFrom   = now()->parse($from)->startOfYear()->toDateString();
        $mtdFrom   = now()->parse($from)->startOfMonth()->toDateString();

        // ── Invoices ──────────────────────────────────────────────────────────
        $invoices = DB::table('sales_invoices')
            ->whereBetween('invoice_date', [$from, $to])
            ->whereNotIn('status', ['cancelled'])
            ->when($locationId,   fn($q) => $q->where('location_id',   $locationId))
            ->when($dimensionId,  fn($q) => $q->where('dimension_id',  $dimensionId))
            ->when($dimension2Id, fn($q) => $q->where('dimension2_id', $dimension2Id))
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount_total),0) as total')
            ->first();

        // Outstanding (total - allocated)
        $outstanding = DB::table('sales_invoices as i')
            ->leftJoin(DB::raw('(SELECT inv_id, SUM(amount) as alloc FROM debtor_allocations GROUP BY inv_id) a'), 'a.inv_id', '=', 'i.id')
            ->whereNotIn('i.status', ['cancelled'])
            ->selectRaw('COALESCE(SUM(i.amount_total - IFNULL(a.alloc,0)),0) as outstanding')
            ->value('outstanding');

        // ── Payments ──────────────────────────────────────────────────────────
        $payments = DB::table('customer_payments')
            ->whereBetween('payment_date', [$from, $to])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount),0) as total, COALESCE(SUM(unallocated_amount),0) as unallocated')
            ->first();

        $paymentTypes = DB::table('customer_payments')
            ->whereBetween('payment_date', [$from, $to])
            ->selectRaw('payment_type, COUNT(*) as count, COALESCE(SUM(amount),0) as total')
            ->groupBy('payment_type')
            ->get();

        // ── M-Pesa ────────────────────────────────────────────────────────────
        $mpesa = DB::table('mpesa_payments')
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN transfer_user != "" AND transfer_user IS NOT NULL THEN 1 ELSE 0 END) as transferred,
                SUM(CASE WHEN transfer_user = "" OR transfer_user IS NULL THEN 1 ELSE 0 END) as pending,
                COALESCE(SUM(TransAmount),0) as total_amount,
                COALESCE(SUM(CASE WHEN transfer_user = "" OR transfer_user IS NULL THEN TransAmount ELSE 0 END),0) as pending_amount
            ')
            ->first();

        // ── Deposits ──────────────────────────────────────────────────────────
        $deposits = DB::table('customer_deposits')
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(deposit_amount),0) as total, COALESCE(SUM(unallocated_amount),0) as unallocated')
            ->first();

        // ── Top customers ─────────────────────────────────────────────────────
        $topCustomers = DB::table('sales_invoices as i')
            ->join('customers as c', 'c.debtor_no', '=', 'i.debtor_no')
            ->whereBetween('i.invoice_date', [$from, $to])
            ->whereNotIn('i.status', ['cancelled'])
            ->when($locationId,   fn($q) => $q->where('i.location_id',   $locationId))
            ->when($dimensionId,  fn($q) => $q->where('i.dimension_id',  $dimensionId))
            ->when($dimension2Id, fn($q) => $q->where('i.dimension2_id', $dimension2Id))
            ->selectRaw('c.name, i.debtor_no, COUNT(*) as inv_count, COALESCE(SUM(i.amount_total),0) as total')
            ->groupBy('i.debtor_no', 'c.name')
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        // ── Recent payments ───────────────────────────────────────────────────
        $recentPayments = DB::table('customer_payments as p')
            ->join('customers as c', 'c.debtor_no', '=', 'p.debtor_no')
            ->orderByDesc('p.created_at')
            ->limit(8)
            ->get(['p.payment_no', 'p.payment_date', 'p.amount', 'p.payment_type', 'p.unallocated_amount', 'c.name', 'p.debtor_no']);

        // ── Customers ─────────────────────────────────────────────────────────
        $customerCount = DB::table('customers')->count();

        // ── Allocations ───────────────────────────────────────────────────────
        $allocations = DB::table('debtor_allocations')
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount),0) as total')
            ->first();

        // ── P&L ───────────────────────────────────────────────────────────────
        $revenue     = round((float) $invoices->total, 2);
        $prevRevenue = round((float) DB::table('sales_invoices')
            ->whereBetween('invoice_date', [$prevFrom, $prevTo])
            ->whereNotIn('status', ['cancelled'])
            ->when($locationId,   fn($q) => $q->where('location_id',   $locationId))
            ->when($dimensionId,  fn($q) => $q->where('dimension_id',  $dimensionId))
            ->when($dimension2Id, fn($q) => $q->where('dimension2_id', $dimension2Id))
            ->sum('amount_total'), 2);
        $ytdRevenue  = round((float) DB::table('sales_invoices')
            ->whereBetween('invoice_date', [$ytdFrom, $to])
            ->whereNotIn('status', ['cancelled'])
            ->when($locationId,   fn($q) => $q->where('location_id',   $locationId))
            ->when($dimensionId,  fn($q) => $q->where('dimension_id',  $dimensionId))
            ->when($dimension2Id, fn($q) => $q->where('dimension2_id', $dimension2Id))
            ->sum('amount_total'), 2);

        $cogs = round((float) DB::table('gld_transactions')
            ->where('account_code', 'LIKE', '5%')
            ->whereBetween('tran_date', [$from, $to])
            ->sum('amount'), 2);
        $prevCogs = round((float) DB::table('gld_transactions')
            ->where('account_code', 'LIKE', '5%')
            ->whereBetween('tran_date', [$prevFrom, $prevTo])
            ->sum('amount'), 2);
        $ytdCogs = round((float) DB::table('gld_transactions')
            ->where('account_code', 'LIKE', '5%')
            ->whereBetween('tran_date', [$ytdFrom, $to])
            ->sum('amount'), 2);

        $grossProfit = round($revenue - $cogs, 2);
        $grossMargin = $revenue > 0 ? round(($grossProfit / $revenue) * 100, 1) : 0;

        $opExpenseCodes = ['BAD_DEBT', 'BANK_CHARGES', 'DISCOUNT'];
        $opExpenses = round((float) DB::table('gld_transactions')
            ->whereIn('account_code', $opExpenseCodes)
            ->whereBetween('tran_date', [$from, $to])
            ->where('amount', '>', 0)
            ->sum('amount'), 2);
        $prevOpExpenses = round((float) DB::table('gld_transactions')
            ->whereIn('account_code', $opExpenseCodes)
            ->whereBetween('tran_date', [$prevFrom, $prevTo])
            ->where('amount', '>', 0)
            ->sum('amount'), 2);
        $ytdOpExpenses = round((float) DB::table('gld_transactions')
            ->whereIn('account_code', $opExpenseCodes)
            ->whereBetween('tran_date', [$ytdFrom, $to])
            ->where('amount', '>', 0)
            ->sum('amount'), 2);

        $netProfit  = round($grossProfit - $opExpenses, 2);
        $netMargin  = $revenue > 0 ? round(($netProfit / $revenue) * 100, 1) : 0;
        $prevNetProfit = round(($prevRevenue - $prevCogs) - $prevOpExpenses, 2);
        $ytdNetProfit  = round(($ytdRevenue  - $ytdCogs)  - $ytdOpExpenses,  2);

        // MTD (start of selected month → $to)
        $mtdRevenue = round((float) DB::table('sales_invoices')
            ->whereBetween('invoice_date', [$mtdFrom, $to])
            ->whereNotIn('status', ['cancelled'])
            ->when($locationId,   fn($q) => $q->where('location_id',   $locationId))
            ->when($dimensionId,  fn($q) => $q->where('dimension_id',  $dimensionId))
            ->when($dimension2Id, fn($q) => $q->where('dimension2_id', $dimension2Id))
            ->sum('amount_total'), 2);
        $mtdCogs = round((float) DB::table('gld_transactions')
            ->where('account_code', 'LIKE', '5%')
            ->whereBetween('tran_date', [$mtdFrom, $to])
            ->sum('amount'), 2);
        $mtdOpExpenses = round((float) DB::table('gld_transactions')
            ->whereIn('account_code', $opExpenseCodes)
            ->whereBetween('tran_date', [$mtdFrom, $to])
            ->where('amount', '>', 0)
            ->sum('amount'), 2);
        $mtdNetProfit = round(($mtdRevenue - $mtdCogs) - $mtdOpExpenses, 2);
        $mtdNetMargin = $mtdRevenue > 0 ? round(($mtdNetProfit / $mtdRevenue) * 100, 1) : 0;

        // ── Category sales breakdown ───────────────────────────────────────────
        $catQuery = fn($dateFrom, $dateTo) =>
            DB::table('sales_invoice_items as li')
                ->join('sales_invoices as i', 'i.id', '=', 'li.inv_id')
                ->join('items as it', 'it.stock_id', '=', 'li.stock_id')
                ->leftJoin('item_categories as c', 'c.id', '=', 'it.category_id')
                ->where('i.status', '!=', 'cancelled')
                ->whereBetween('i.invoice_date', [$dateFrom, $dateTo])
                ->when($locationId,   fn($q) => $q->where('i.location_id',   $locationId))
                ->when($dimensionId,  fn($q) => $q->where('i.dimension_id',  $dimensionId))
                ->when($dimension2Id, fn($q) => $q->where('i.dimension2_id', $dimension2Id))
                ->selectRaw("COALESCE(c.name,'Other') as category, COALESCE(SUM(li.line_total),0) as total")
                ->groupBy('c.id', 'c.name')
                ->orderByDesc('total')
                ->get()
                ->keyBy('category');

        $catPeriod = $catQuery($from, $to);
        $catPrev   = $catQuery($prevFrom, $prevTo);
        $catYtd    = $catQuery($ytdFrom, $to);
        $catMtd    = $catQuery($mtdFrom, $to);

        // Merge all category keys
        $allCats = collect($catPeriod->keys())
            ->merge($catPrev->keys())
            ->merge($catYtd->keys())
            ->merge($catMtd->keys())
            ->unique()->values();

        $pct = fn($cur, $prev) => $prev != 0 ? round((($cur - $prev) / abs($prev)) * 100, 1) : null;

        $categoryRows = $allCats->map(fn($cat) => [
            'category'   => $cat,
            'period'     => round((float)($catPeriod[$cat]->total ?? 0), 2),
            'mtd'        => round((float)($catMtd[$cat]->total    ?? 0), 2),
            'ytd'        => round((float)($catYtd[$cat]->total    ?? 0), 2),
            'change_pct' => $pct($catPeriod[$cat]->total ?? 0, $catPrev[$cat]->total ?? 0),
        ])->values();

        // ── Store performance ─────────────────────────────────────────────────
        $storePerf = DB::table('sales_invoices as i')
            ->leftJoin('inventory_locations as l', 'l.id', '=', 'i.location_id')
            ->join('sales_invoice_items as li', 'li.inv_id', '=', 'i.id')
            ->where('i.status', '!=', 'cancelled')
            ->whereBetween('i.invoice_date', [$from, $to])
            ->when($locationId,   fn($q) => $q->where('i.location_id',   $locationId))
            ->when($dimensionId,  fn($q) => $q->where('i.dimension_id',  $dimensionId))
            ->when($dimension2Id, fn($q) => $q->where('i.dimension2_id', $dimension2Id))
            ->selectRaw('
                i.location_id,
                COALESCE(l.name, "Unknown") as loc_name,
                COALESCE(l.code, "UNK") as loc_code,
                COALESCE(l.type, "") as loc_type,
                COUNT(DISTINCT i.id) as inv_count,
                COALESCE(SUM(li.line_total), 0) as revenue,
                COALESCE(SUM(li.qty * li.standard_cost), 0) as cogs
            ')
            ->groupBy('i.location_id', 'l.name', 'l.code', 'l.type')
            ->orderByDesc('revenue')
            ->get();

        $prevStorePerf = DB::table('sales_invoices as i')
            ->leftJoin('inventory_locations as l', 'l.id', '=', 'i.location_id')
            ->join('sales_invoice_items as li', 'li.inv_id', '=', 'i.id')
            ->where('i.status', '!=', 'cancelled')
            ->whereBetween('i.invoice_date', [$prevFrom, $prevTo])
            ->selectRaw('i.location_id, COALESCE(SUM(li.line_total), 0) as revenue')
            ->groupBy('i.location_id')
            ->get()
            ->keyBy('location_id');

        $storeRows = $storePerf->map(function ($row) use ($prevStorePerf, $pct) {
            $revenue     = round((float) $row->revenue, 2);
            $cogs        = round((float) $row->cogs, 2);
            $gp          = round($revenue - $cogs, 2);
            $margin      = $revenue > 0 ? round(($gp / $revenue) * 100, 1) : 0;
            $prevRev     = (float) ($prevStorePerf[$row->location_id]->revenue ?? 0);
            return [
                'location_id' => $row->location_id,
                'name'        => $row->loc_name,
                'code'        => $row->loc_code,
                'type'        => $row->loc_type,
                'inv_count'   => (int) $row->inv_count,
                'revenue'     => $revenue,
                'cogs'        => $cogs,
                'gross_profit'=> $gp,
                'margin'      => $margin,
                'trend_pct'   => $pct($revenue, $prevRev),
            ];
        })->values();

        return ApiResponse::success([
            'period'         => ['from' => $from, 'to' => $to, 'prev_from' => $prevFrom, 'prev_to' => $prevTo],
            'invoices'       => [
                'count'       => (int) $invoices->count,
                'total'       => (float) $invoices->total,
                'outstanding' => round((float) $outstanding, 2),
            ],
            'payments'       => [
                'count'       => (int) $payments->count,
                'total'       => (float) $payments->total,
                'unallocated' => (float) $payments->unallocated,
                'by_type'     => $paymentTypes,
            ],
            'mpesa'          => [
                'total'          => (int) $mpesa->total,
                'transferred'    => (int) $mpesa->transferred,
                'pending'        => (int) $mpesa->pending,
                'total_amount'   => (float) $mpesa->total_amount,
                'pending_amount' => (float) $mpesa->pending_amount,
            ],
            'deposits'       => [
                'count'       => (int) $deposits->count,
                'total'       => (float) $deposits->total,
                'unallocated' => (float) $deposits->unallocated,
            ],
            'customers'      => (int) $customerCount,
            'allocations'    => [
                'count' => (int) $allocations->count,
                'total' => (float) $allocations->total,
            ],
            'pnl'            => [
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
            'category_sales' => $categoryRows,
            'top_customers'    => $topCustomers,
            'store_performance'=> $storeRows,
        ], 'Sales dashboard KPIs');
    }

    public function storeItems(Request $request): JsonResponse
    {
        $locationId    = $request->get('location_id');
        $nullLocation  = $request->boolean('null_location'); // true = show invoices with no location
        $from = $request->get('date_from', now()->startOfMonth()->toDateString());
        $to   = $request->get('date_to',   now()->toDateString());

        $items = DB::table('sales_invoice_items as li')
            ->join('sales_invoices as i', 'i.id', '=', 'li.inv_id')
            ->leftJoin('items as it', 'it.stock_id', '=', 'li.stock_id')
            ->leftJoin('item_categories as c', 'c.id', '=', 'it.category_id')
            ->where('i.status', '!=', 'cancelled')
            ->whereBetween('i.invoice_date', [$from, $to])
            ->when($nullLocation, fn($q) => $q->whereNull('i.location_id'))
            ->when(!$nullLocation && $locationId !== null && $locationId !== '', fn($q) => $q->where('i.location_id', $locationId))
            ->selectRaw('
                li.stock_id,
                li.description,
                COALESCE(c.name, "Uncategorised") as category,
                SUM(li.qty) as qty_sold,
                li.unit,
                COALESCE(AVG(li.price), 0) as avg_price,
                COALESCE(SUM(li.line_total), 0) as revenue,
                COALESCE(SUM(li.qty * li.standard_cost), 0) as cogs
            ')
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
        $from = $request->get('date_from', now()->startOfMonth()->toDateString());
        $to   = $request->get('date_to',   now()->toDateString());

        // Farmers — raw collection from individual farmer deliveries
        $farmers = (float) DB::table('milk_purchase_items')
            ->join('milk_purchases', 'milk_purchases.id', '=', 'milk_purchase_items.purchase_id')
            ->whereBetween('milk_purchases.invoice_date', [$from, $to])
            ->sum('milk_purchase_items.quantity');

        // Center — received into GRN (filter by GRN delivery date)
        $center = (float) DB::table('milk_grn_items')
            ->join('milk_grn_batches', 'milk_grn_batches.id', '=', 'milk_grn_items.grn_batch_id')
            ->whereBetween('milk_grn_batches.delivery_date', [$from, $to])
            ->sum('milk_grn_items.qty_received');

        // Grader — filter by the grader's own collection date, not the purchase date
        $grader = (float) DB::table('milk_grader_collections')
            ->whereBetween('date_collected', [$from, $to])
            ->sum('quantity');

        // Factory — location transfers to factory (if any)
        $factory = (float) DB::table('milk_location_transfers')
            ->whereBetween('transfer_date', [$from, $to])
            ->sum('quantity');

        // Sold — raw milk sold via sales invoices (stock_id = 0001)
        $sold = (float) DB::table('sales_invoice_items')
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_items.inv_id')
            ->whereBetween('sales_invoices.invoice_date', [$from, $to])
            ->where('sales_invoice_items.stock_id', '0001')
            ->sum('sales_invoice_items.qty');

        return ApiResponse::success([
            'period'   => ['from' => $from, 'to' => $to],
            'pipeline' => [
                ['stage' => 'Farmers',   'qty' => round($farmers, 1), 'icon' => 'farmers'],
                ['stage' => 'Center',    'qty' => round($center,  1), 'icon' => 'center'],
                ['stage' => 'Grader',    'qty' => round($grader,  1), 'icon' => 'grader'],
                ['stage' => 'Factory',   'qty' => round($factory, 1), 'icon' => 'factory'],
                ['stage' => 'Sold',      'qty' => round($sold,    1), 'icon' => 'sold'],
            ],
        ], 'Milk traceability loaded');
    }

    public function graderCollections(Request $request): JsonResponse
    {
        $from = $request->get('date_from', now()->startOfMonth()->toDateString());
        $to   = $request->get('date_to',   now()->toDateString());

        $rows = DB::table('milk_grader_collections as gc')
            ->join('milk_purchases as mp', 'mp.id', '=', 'gc.purchase_id')
            ->leftJoin('milk_routes as mr', 'mr.id', '=', 'mp.route_id')
            ->whereBetween('gc.date_collected', [$from, $to])
            ->selectRaw('
                gc.location            AS grader_code,
                COALESCE(mr.route_name, "Unknown") AS route_name,
                COALESCE(mr.route_code, "-")       AS route_code,
                COUNT(DISTINCT gc.purchase_id)     AS sessions,
                SUM(gc.quantity)                   AS total_qty,
                SUM(gc.quantity * gc.rate)         AS total_amount,
                AVG(gc.rate)                       AS avg_rate,
                MAX(gc.date_collected)             AS last_collection
            ')
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
        $from       = $request->get('date_from', now()->startOfMonth()->toDateString());
        $to         = $request->get('date_to',   now()->toDateString());

        // ── Farmers collected for this grader ───────────────────────────────
        $farmers = DB::table('milk_grader_collections as gc')
            ->join('milk_purchases as mp',       'mp.id',  '=', 'gc.purchase_id')
            ->join('milk_purchase_items as mpi', 'mpi.purchase_id', '=', 'mp.id')
            ->leftJoin('farmers as f',           'f.id',   '=', 'mpi.farmer_id')
            ->where('gc.location', $graderCode)
            ->whereBetween('gc.date_collected', [$from, $to])
            ->groupBy('f.id', 'f.farmer_no', 'f.full_name', 'f.phone')
            ->selectRaw('
                COALESCE(f.farmer_no,   "Unknown") AS farmer_no,
                COALESCE(f.full_name,   "Unknown") AS farmer_name,
                COALESCE(f.phone,       "")        AS phone,
                SUM(mpi.quantity)                  AS total_qty,
                SUM(mpi.total_price)               AS total_amount,
                AVG(mpi.unit_price)                AS avg_rate,
                COUNT(DISTINCT gc.date_collected)  AS sessions,
                MAX(gc.date_collected)             AS last_collection
            ')
            ->orderByDesc('total_qty')
            ->get();

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
