<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Traits\StatisticalAnalysisTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesAnalyticsController extends Controller
{
    use StatisticalAnalysisTrait;

    // ── Endpoint: forecast ────────────────────────────────────────────────────

    public function forecast(Request $request): JsonResponse
    {
        $horizonDays = (int) $request->get('horizon', 30);
        $histDays    = (int) $request->get('history', 90);

        $histFrom = now()->subDays($histDays - 1)->toDateString();
        $histTo   = now()->toDateString();

        $rawRows = DB::table('sales_invoices')
            ->whereBetween('invoice_date', [$histFrom, $histTo])
            ->whereIn('status', ['posted', 'paid', 'partial'])
            ->selectRaw('DATE(invoice_date) AS date, SUM(amount_total) AS amount, COUNT(*) AS inv_count')
            ->groupBy(DB::raw('DATE(invoice_date)'))
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        // Fill calendar gaps with zero
        $allDates = [];
        $cur      = Carbon::parse($histFrom);
        $end      = Carbon::parse($histTo);
        while ($cur->lte($end)) {
            $d   = $cur->toDateString();
            $row = $rawRows->get($d);
            $allDates[] = [
                'date'      => $d,
                'amount'    => $row ? round((float) $row->amount, 2) : 0,
                'inv_count' => $row ? (int) $row->inv_count : 0,
            ];
            $cur->addDay();
        }

        $amounts   = array_column($allDates, 'amount');
        $reg       = $this->linearRegression($amounts);
        $seasonal  = $this->seasonalFactors($allDates, 'date', 'amount');
        $mae       = $this->mae($amounts, $reg['slope'], $reg['intercept']);
        $movAvg    = $this->movingAvg($amounts);

        foreach ($allDates as $i => &$row) {
            $row['ma7']       = round($movAvg[$i], 2);
            $row['trend_val'] = round($reg['slope'] * $i + $reg['intercept'], 2);
        }
        unset($row);

        $n           = count($allDates);
        $predictions = [];
        for ($i = 0; $i < $horizonDays; $i++) {
            $futureDate = Carbon::parse($histTo)->addDays($i + 1);
            $dow        = $futureDate->dayOfWeek;
            $sf         = $seasonal[$dow] ?? 1.0;
            $base       = $reg['slope'] * ($n + $i) + $reg['intercept'];
            $predicted  = max(0, $base * $sf);
            $predictions[] = [
                'date'   => $futureDate->toDateString(),
                'amount' => round($predicted, 2),
                'lower'  => round(max(0, $predicted - 1.5 * $mae), 2),
                'upper'  => round($predicted + 1.5 * $mae, 2),
            ];
        }

        $trend       = $this->classifyTrend($reg['slope'], $reg['intercept'], $n);
        $mean        = count($amounts) ? array_sum($amounts) / count($amounts) : 0;
        $variance    = 0;
        foreach ($amounts as $v) { $variance += ($v - $mean) ** 2; }
        $std         = count($amounts) > 1 ? sqrt($variance / count($amounts)) : 0;
        $cv          = $mean > 0 ? round($std / $mean * 100, 1) : 0;
        $proj30d     = round(array_sum(array_column(array_slice($predictions, 0, 30), 'amount')), 2);

        return ApiResponse::success([
            'history'     => $allDates,
            'predictions' => $predictions,
            'model'       => [
                'slope'     => round($reg['slope'], 4),
                'intercept' => round($reg['intercept'], 2),
                'r2'        => $reg['r2'],
                'mae'       => round($mae, 2),
                'trend'     => $trend['label'],
                'trend_pct' => $trend['pct'],
                'cv'        => $cv,
                'proj_30d'  => $proj30d,
            ],
        ], 'Sales forecast computed');
    }

    // ── Endpoint: insights ────────────────────────────────────────────────────

    public function insights(Request $request): JsonResponse
    {
        $to   = now()->toDateString();
        $from = now()->subDays(59)->toDateString();
        $prev = now()->subDays(119)->toDateString();
        $prevTo = now()->subDays(60)->toDateString();

        // Current period summary
        $current = DB::table('sales_invoices')
            ->whereBetween('invoice_date', [$from, $to])
            ->whereIn('status', ['posted', 'paid', 'partial'])
            ->selectRaw('SUM(amount_total) AS amount, COUNT(*) AS inv_count, COUNT(DISTINCT debtor_no) AS customer_count')
            ->first();

        // Prior period summary
        $prior = DB::table('sales_invoices')
            ->whereBetween('invoice_date', [$prev, $prevTo])
            ->whereIn('status', ['posted', 'paid', 'partial'])
            ->selectRaw('SUM(amount_total) AS amount, COUNT(*) AS inv_count, COUNT(DISTINCT debtor_no) AS customer_count')
            ->first();

        $curAmt  = (float)($current->amount ?? 0);
        $prAmt   = (float)($prior->amount   ?? 0);
        $curInv  = (int)($current->inv_count ?? 0);
        $prInv   = (int)($prior->inv_count   ?? 0);
        $curCust = (int)($current->customer_count ?? 0);
        $prCust  = (int)($prior->customer_count   ?? 0);

        $growthAmt  = $prAmt  > 0 ? round(($curAmt  - $prAmt)  / $prAmt  * 100, 1) : null;
        $growthInv  = $prInv  > 0 ? round(($curInv  - $prInv)  / $prInv  * 100, 1) : null;
        $growthCust = $prCust > 0 ? round(($curCust - $prCust) / $prCust * 100, 1) : null;
        $avgInvVal  = $curInv > 0 ? round($curAmt / $curInv, 2) : 0;

        // Top 10 customers by revenue
        $topCustomers = DB::table('sales_invoices as si')
            ->join('customers as c', 'c.debtor_no', '=', 'si.debtor_no')
            ->whereBetween('si.invoice_date', [$from, $to])
            ->whereIn('si.status', ['posted', 'paid', 'partial'])
            ->selectRaw('si.debtor_no, c.name, SUM(si.amount_total) AS amount, COUNT(*) AS inv_count')
            ->groupBy('si.debtor_no', 'c.name')
            ->orderByDesc('amount')
            ->limit(10)
            ->get();

        // Prior period per customer for growth calculation
        $custPrior = DB::table('sales_invoices')
            ->whereBetween('invoice_date', [$prev, $prevTo])
            ->whereIn('status', ['posted', 'paid', 'partial'])
            ->selectRaw('debtor_no, SUM(amount_total) AS amount_prev')
            ->groupBy('debtor_no')
            ->get()
            ->keyBy('debtor_no');

        $topCustomers = $topCustomers->map(function ($c) use ($custPrior, $curAmt) {
            $prev   = $custPrior->get($c->debtor_no);
            $prevAmt = $prev ? (float)$prev->amount_prev : 0;
            $growth  = $prevAmt > 0 ? round(((float)$c->amount - $prevAmt) / $prevAmt * 100, 1) : null;
            return [
                'debtor_no' => $c->debtor_no,
                'name'      => $c->name,
                'amount'    => round((float)$c->amount, 2),
                'inv_count' => (int)$c->inv_count,
                'share'     => $curAmt > 0 ? round((float)$c->amount / $curAmt * 100, 1) : 0,
                'growth'    => $growth,
            ];
        });

        // Revenue by sales area
        $areaBreakdown = DB::table('sales_invoices as si')
            ->joinSub(
                DB::table('customer_branches')
                    ->select('debtor_no', DB::raw('MIN(id) as min_id'))
                    ->groupBy('debtor_no'),
                'cb_min', fn($j) => $j->on('cb_min.debtor_no', '=', 'si.debtor_no')
            )
            ->join('customer_branches as cb', 'cb.id', '=', 'cb_min.min_id')
            ->leftJoin('sales_areas as sa', 'sa.id', '=', 'cb.sales_area_id')
            ->whereBetween('si.invoice_date', [$from, $to])
            ->whereIn('si.status', ['posted', 'paid', 'partial'])
            ->selectRaw("COALESCE(sa.area_name, 'Unassigned') AS area_name, SUM(si.amount_total) AS amount, COUNT(*) AS inv_count")
            ->groupBy('sa.id', 'sa.area_name')
            ->orderByDesc('amount')
            ->get();

        // Average days to payment (invoice_date → debtor_allocations.allocated_date)
        $avgDaysToPayment = DB::table('sales_invoices as si')
            ->join('debtor_allocations as da', 'da.inv_id', '=', 'si.id')
            ->whereBetween('si.invoice_date', [$from, $to])
            ->whereIn('si.status', ['paid', 'partial'])
            ->selectRaw('AVG(DATEDIFF(da.allocated_date, si.invoice_date)) AS avg_days')
            ->value('avg_days');

        // Daily revenue for anomaly detection
        $dailyRows = DB::table('sales_invoices')
            ->whereBetween('invoice_date', [$from, $to])
            ->whereIn('status', ['posted', 'paid', 'partial'])
            ->selectRaw('DATE(invoice_date) AS date, SUM(amount_total) AS amount')
            ->groupBy(DB::raw('DATE(invoice_date)'))
            ->orderBy('date')
            ->get()
            ->toArray();

        $dailyAmounts = array_map(fn($r) => (float)$r->amount, $dailyRows);
        $anomalies    = $this->detectAnomalies(
            $dailyAmounts,
            array_map('get_object_vars', $dailyRows),
            'date',
            'amount'
        );

        return ApiResponse::success([
            'period'         => ['from' => $from, 'to' => $to],
            'summary'        => [
                'amount'         => round($curAmt, 2),
                'inv_count'      => $curInv,
                'customer_count' => $curCust,
                'avg_inv_value'  => $avgInvVal,
                'growth_amount'  => $growthAmt,
                'growth_invoices'=> $growthInv,
                'growth_customers'=> $growthCust,
            ],
            'top_customers'  => $topCustomers->values(),
            'area_breakdown' => $areaBreakdown->values(),
            'avg_days_to_payment' => $avgDaysToPayment ? round((float)$avgDaysToPayment, 1) : null,
            'anomalies'      => $anomalies,
        ], 'Sales insights computed');
    }

    // ── Endpoint: AI advice via Groq ─────────────────────────────────────────

    public function advice(Request $request): JsonResponse
    {
        $to     = now()->toDateString();
        $from   = now()->subDays(59)->toDateString();
        $prev   = now()->subDays(119)->toDateString();
        $prevTo = now()->subDays(60)->toDateString();

        $cur = DB::table('sales_invoices')
            ->whereBetween('invoice_date', [$from, $to])
            ->whereIn('status', ['posted', 'paid', 'partial'])
            ->selectRaw('SUM(amount_total) AS amount, COUNT(*) AS inv_count, COUNT(DISTINCT debtor_no) AS customers')
            ->first();

        $prv = DB::table('sales_invoices')
            ->whereBetween('invoice_date', [$prev, $prevTo])
            ->whereIn('status', ['posted', 'paid', 'partial'])
            ->selectRaw('SUM(amount_total) AS amount, COUNT(*) AS inv_count')
            ->first();

        $topCustomers = DB::table('sales_invoices as si')
            ->join('customers as c', 'c.debtor_no', '=', 'si.debtor_no')
            ->whereBetween('si.invoice_date', [$from, $to])
            ->whereIn('si.status', ['posted', 'paid', 'partial'])
            ->selectRaw('c.name, SUM(si.amount_total) AS amount')
            ->groupBy('si.debtor_no', 'c.name')
            ->orderByDesc('amount')
            ->limit(5)
            ->get();

        $avgDays = DB::table('sales_invoices as si')
            ->join('debtor_allocations as da', 'da.inv_id', '=', 'si.id')
            ->whereBetween('si.invoice_date', [$from, $to])
            ->selectRaw('AVG(DATEDIFF(da.allocated_date, si.invoice_date)) AS avg_days')
            ->value('avg_days');

        $curAmt = round((float)($cur->amount ?? 0), 2);
        $prvAmt = round((float)($prv->amount ?? 0), 2);
        $curInv = (int)($cur->inv_count ?? 0);
        $prvInv = (int)($prv->inv_count ?? 0);
        $customers = (int)($cur->customers ?? 0);
        $avgInvVal = $curInv > 0 ? round($curAmt / $curInv, 2) : 0;
        $growthAmt = $prvAmt > 0 ? round(($curAmt - $prvAmt) / $prvAmt * 100, 1) : 'N/A';
        $growthInv = $prvInv > 0 ? round(($curInv - $prvInv) / $prvInv * 100, 1) : 'N/A';

        $custList = $topCustomers->map(fn($c) => "  - {$c->name}: KES " . number_format((float)$c->amount, 2))->implode("\n");

        $prompt = <<<PROMPT
You are a senior ERP sales intelligence advisor for an agricultural business in Kenya. Analyze the following 60-day sales data and provide structured, actionable business advice. Currency is Kenyan Shillings (KES).

SALES DATA (last 60 days vs prior 60 days):
- Total revenue: KES {$curAmt} (vs KES {$prvAmt}, growth: {$growthAmt}%)
- Total invoices: {$curInv} (vs {$prvInv}, change: {$growthInv}%)
- Active customers: {$customers}
- Average invoice value: KES {$avgInvVal}
- Average days to payment: {$avgDays} days
- Top customers by revenue:
{$custList}

Respond ONLY with a valid JSON object (no markdown, no extra text) with this exact structure:
{
  "headline": "One-sentence overall sales health assessment",
  "score": <integer 1-10>,
  "pricing_strategy": {
    "insight": "What average invoice value and revenue trends indicate",
    "recommendation": "Specific pricing or upsell action"
  },
  "customer_retention": {
    "insight": "What customer concentration and payment trends indicate",
    "recommendation": "Specific retention or collection action"
  },
  "growth_opportunities": {
    "insight": "Where untapped revenue potential exists",
    "recommendation": "Specific growth action to pursue"
  },
  "risk_management": {
    "insight": "Key sales risks identified in the data",
    "recommendation": "Specific risk mitigation step"
  },
  "risks": ["risk 1", "risk 2", "risk 3"],
  "opportunities": ["opportunity 1", "opportunity 2", "opportunity 3"],
  "quick_wins": ["actionable item completable in < 30 days", "second quick win"]
}
PROMPT;

        try {
            $advice = $this->callGroq($prompt);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }

        if (isset($advice['error']) && $advice['error'] === 'no_key') {
            return ApiResponse::success([
                'error'   => 'no_key',
                'message' => 'GROQ_API_KEY is not configured in .env.',
            ], 'No API key');
        }

        return ApiResponse::success([
            'advice'    => $advice,
            'generated' => now()->toISOString(),
        ], 'Sales AI advice generated');
    }
}
