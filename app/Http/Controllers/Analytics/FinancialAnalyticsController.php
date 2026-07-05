<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class FinancialAnalyticsController extends Controller
{
    public function summary(): JsonResponse
    {
        $now         = now();
        $period60End = $now->toDateString();
        $period60Beg = $now->copy()->subDays(59)->toDateString();
        $priorBeg    = $now->copy()->subDays(119)->toDateString();
        $priorEnd    = $now->copy()->subDays(60)->toDateString();
        $sixMoBeg    = $now->copy()->subMonths(6)->startOfMonth()->toDateString();

        // ── Monthly revenue ──────────────────────────────────────────────────
        $monthlyRevRaw = DB::table('sales_invoices')
            ->whereIn('status', ['posted', 'paid', 'partial'])
            ->where('invoice_date', '>=', $sixMoBeg)
            ->selectRaw("DATE_FORMAT(invoice_date,'%Y-%m') AS month, SUM(amount_total) AS revenue, COUNT(*) AS inv_count")
            ->groupBy(DB::raw("DATE_FORMAT(invoice_date,'%Y-%m')"))
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        // ── Monthly spend (approved/received POs) ────────────────────────────
        $monthlySpendRaw = DB::table('purchase_orders')
            ->whereNotIn('status', ['draft', 'cancelled', 'rejected'])
            ->where('order_date', '>=', $sixMoBeg)
            ->selectRaw("DATE_FORMAT(order_date,'%Y-%m') AS month, SUM(amount_total) AS spend, COUNT(*) AS po_count")
            ->groupBy(DB::raw("DATE_FORMAT(order_date,'%Y-%m')"))
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        // ── Monthly bank cash in / out ───────────────────────────────────────
        $monthlyCashIn = DB::table('bank_deposits')
            ->whereIn('status', ['posted', 'approved'])
            ->where('deposit_date', '>=', $sixMoBeg)
            ->selectRaw("DATE_FORMAT(deposit_date,'%Y-%m') AS month, SUM(amount) AS cash_in")
            ->groupBy(DB::raw("DATE_FORMAT(deposit_date,'%Y-%m')"))
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $monthlyCashOut = DB::table('bank_payments')
            ->whereIn('status', ['posted', 'approved'])
            ->where('payment_date', '>=', $sixMoBeg)
            ->selectRaw("DATE_FORMAT(payment_date,'%Y-%m') AS month, SUM(amount) AS cash_out")
            ->groupBy(DB::raw("DATE_FORMAT(payment_date,'%Y-%m')"))
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        // ── Build all months in range ────────────────────────────────────────
        $allMonthKeys = collect($monthlyRevRaw->keys())
            ->merge($monthlySpendRaw->keys())
            ->merge($monthlyCashIn->keys())
            ->merge($monthlyCashOut->keys())
            ->unique()->sort()->values();

        $monthly = $allMonthKeys->map(function ($m) use ($monthlyRevRaw, $monthlySpendRaw, $monthlyCashIn, $monthlyCashOut) {
            $rev    = (float) ($monthlyRevRaw->get($m)?->revenue ?? 0);
            $spend  = (float) ($monthlySpendRaw->get($m)?->spend ?? 0);
            $cin    = (float) ($monthlyCashIn->get($m)?->cash_in ?? 0);
            $cout   = (float) ($monthlyCashOut->get($m)?->cash_out ?? 0);
            $profit = $rev - $spend;
            $margin = $rev > 0 ? round($profit / $rev * 100, 1) : 0;

            return [
                'month'     => $m,
                'revenue'   => round($rev, 2),
                'spend'     => round($spend, 2),
                'profit'    => round($profit, 2),
                'margin'    => $margin,
                'cash_in'   => round($cin, 2),
                'cash_out'  => round($cout, 2),
                'net_cash'  => round($cin - $cout, 2),
                'inv_count' => (int) ($monthlyRevRaw->get($m)?->inv_count ?? 0),
                'po_count'  => (int) ($monthlySpendRaw->get($m)?->po_count ?? 0),
            ];
        })->values()->all();

        // ── 60-day totals ────────────────────────────────────────────────────
        $revenue60 = (float) DB::table('sales_invoices')
            ->whereIn('status', ['posted', 'paid', 'partial'])
            ->whereBetween('invoice_date', [$period60Beg, $period60End])
            ->sum('amount_total');

        $spend60 = (float) DB::table('purchase_orders')
            ->whereNotIn('status', ['draft', 'cancelled', 'rejected'])
            ->whereBetween('order_date', [$period60Beg, $period60End])
            ->sum('amount_total');

        $cashIn60 = (float) DB::table('bank_deposits')
            ->whereIn('status', ['posted', 'approved'])
            ->whereBetween('deposit_date', [$period60Beg, $period60End])
            ->sum('amount');

        $cashOut60 = (float) DB::table('bank_payments')
            ->whereIn('status', ['posted', 'approved'])
            ->whereBetween('payment_date', [$period60Beg, $period60End])
            ->sum('amount');

        // ── Prior-period revenue for growth calc ─────────────────────────────
        $priorRevenue = (float) DB::table('sales_invoices')
            ->whereIn('status', ['posted', 'paid', 'partial'])
            ->whereBetween('invoice_date', [$priorBeg, $priorEnd])
            ->sum('amount_total');

        $priorSpend = (float) DB::table('purchase_orders')
            ->whereNotIn('status', ['draft', 'cancelled', 'rejected'])
            ->whereBetween('order_date', [$priorBeg, $priorEnd])
            ->sum('amount_total');

        $grossProfit60 = $revenue60 - $spend60;
        $grossMargin60 = $revenue60 > 0 ? round($grossProfit60 / $revenue60 * 100, 1) : 0;
        $revenueGrowth = $priorRevenue > 0 ? round(($revenue60 - $priorRevenue) / $priorRevenue * 100, 1) : null;
        $spendGrowth   = $priorSpend   > 0 ? round(($spend60   - $priorSpend)   / $priorSpend   * 100, 1) : null;

        // ── Trajectory (profit margin improving/declining/stable?) ────────────
        $trajectory = 'stable';
        if (count($monthly) >= 2) {
            $firstMargin = $monthly[0]['margin'];
            $lastMargin  = end($monthly)['margin'];
            if ($lastMargin > $firstMargin + 2) {
                $trajectory = 'improving';
            } elseif ($lastMargin < $firstMargin - 2) {
                $trajectory = 'declining';
            }
        }

        return ApiResponse::success([
            'summary' => [
                'revenue_60d'      => round($revenue60, 2),
                'spend_60d'        => round($spend60, 2),
                'gross_profit_60d' => round($grossProfit60, 2),
                'gross_margin'     => $grossMargin60,
                'revenue_growth'   => $revenueGrowth,
                'spend_growth'     => $spendGrowth,
                'cash_in_60d'      => round($cashIn60, 2),
                'cash_out_60d'     => round($cashOut60, 2),
                'net_cash_60d'     => round($cashIn60 - $cashOut60, 2),
                'trajectory'       => $trajectory,
            ],
            'monthly' => $monthly,
        ]);
    }
}
