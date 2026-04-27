<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Traits\StatisticalAnalysisTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManufacturingAnalyticsController extends Controller
{
    use StatisticalAnalysisTrait;

    // ── Endpoint: forecast ────────────────────────────────────────────────────

    public function forecast(Request $request): JsonResponse
    {
        $horizonDays = (int) $request->get('horizon', 30);
        $histDays    = (int) $request->get('history', 90);

        $histFrom = now()->subDays($histDays - 1)->toDateString();
        $histTo   = now()->toDateString();

        // Daily production output from completed work orders
        $rawRows = DB::table('work_orders')
            ->where('status', 'completed')
            ->whereBetween('completed_date', [$histFrom, $histTo])
            ->selectRaw('DATE(completed_date) AS date, SUM(actual_qty_produced) AS qty_produced, COUNT(*) AS wo_count')
            ->groupBy(DB::raw('DATE(completed_date)'))
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        // Fill calendar gaps
        $allDates = [];
        $cur      = Carbon::parse($histFrom);
        $end      = Carbon::parse($histTo);
        while ($cur->lte($end)) {
            $d   = $cur->toDateString();
            $row = $rawRows->get($d);
            $allDates[] = [
                'date'         => $d,
                'qty_produced' => $row ? round((float)$row->qty_produced, 2) : 0,
                'wo_count'     => $row ? (int)$row->wo_count : 0,
            ];
            $cur->addDay();
        }

        $quantities = array_column($allDates, 'qty_produced');
        $reg        = $this->linearRegression($quantities);
        $seasonal   = $this->seasonalFactors($allDates, 'date', 'qty_produced');
        $mae        = $this->mae($quantities, $reg['slope'], $reg['intercept']);
        $movAvg     = $this->movingAvg($quantities);

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
                'date'         => $futureDate->toDateString(),
                'qty_produced' => round($predicted, 2),
                'lower'        => round(max(0, $predicted - 1.5 * $mae), 2),
                'upper'        => round($predicted + 1.5 * $mae, 2),
            ];
        }

        $trend   = $this->classifyTrend($reg['slope'], $reg['intercept'], $n);
        $mean    = count($quantities) ? array_sum($quantities) / count($quantities) : 0;
        $variance = 0;
        foreach ($quantities as $v) { $variance += ($v - $mean) ** 2; }
        $std     = count($quantities) > 1 ? sqrt($variance / count($quantities)) : 0;
        $cv      = $mean > 0 ? round($std / $mean * 100, 1) : 0;
        $proj30d = round(array_sum(array_column(array_slice($predictions, 0, 30), 'qty_produced')), 2);

        // Capacity utilization (actual vs planned in history period)
        $efficiency = DB::table('work_orders')
            ->where('status', 'completed')
            ->whereBetween('completed_date', [$histFrom, $histTo])
            ->selectRaw('SUM(actual_qty_produced) as actual, SUM(planned_qty) as planned')
            ->first();

        $utilization = ($efficiency && $efficiency->planned > 0)
            ? round((float)$efficiency->actual / (float)$efficiency->planned * 100, 1)
            : 0;

        return ApiResponse::success([
            'history'         => $allDates,
            'predictions'     => $predictions,
            'model'           => [
                'slope'         => round($reg['slope'], 4),
                'intercept'     => round($reg['intercept'], 2),
                'r2'            => $reg['r2'],
                'mae'           => round($mae, 2),
                'trend'         => $trend['label'],
                'trend_pct'     => $trend['pct'],
                'cv'            => $cv,
                'proj_30d'      => $proj30d,
            ],
            'utilization_pct' => $utilization,
        ], 'Manufacturing forecast computed');
    }

    // ── Endpoint: insights ────────────────────────────────────────────────────

    public function insights(Request $request): JsonResponse
    {
        $to   = now()->toDateString();
        $from = now()->subDays(59)->toDateString();

        // Work orders by status in period
        $byStatus = DB::table('work_orders')
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->selectRaw('status, COUNT(*) as count, SUM(planned_qty) as planned, SUM(actual_qty_produced) as actual')
            ->groupBy('status')
            ->get()
            ->map(fn($r) => [
                'status'  => $r->status,
                'count'   => (int)$r->count,
                'planned' => round((float)$r->planned, 2),
                'actual'  => round((float)$r->actual, 2),
            ]);

        // Average cycle time per work centre
        $cycleTimes = DB::table('work_orders as wo')
            ->join('work_centres as wc', 'wc.id', '=', 'wo.work_centre_id')
            ->where('wo.status', 'completed')
            ->whereBetween('wo.completed_date', [$from, $to])
            ->selectRaw('wc.name as work_centre,
                         AVG(DATEDIFF(wo.completed_date, wo.start_date)) as avg_cycle_days,
                         COUNT(*) as wo_count,
                         SUM(wo.actual_qty_produced) as total_produced')
            ->groupBy('wc.id', 'wc.name')
            ->orderByDesc('wo_count')
            ->get()
            ->map(fn($r) => [
                'work_centre'    => $r->work_centre,
                'avg_cycle_days' => round((float)$r->avg_cycle_days, 1),
                'wo_count'       => (int)$r->wo_count,
                'total_produced' => round((float)$r->total_produced, 2),
            ]);

        // Cost variance: actual total_cost vs planned (planned_qty * unit_cost)
        $costVariance = DB::table('work_orders')
            ->where('status', 'completed')
            ->whereBetween('completed_date', [$from, $to])
            ->selectRaw('product_code, product_description,
                         SUM(total_cost) as actual_cost,
                         SUM(planned_qty * unit_cost) as expected_cost,
                         SUM(actual_qty_produced) as total_produced')
            ->groupBy('product_code', 'product_description')
            ->orderByDesc('actual_cost')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'product_code'    => $r->product_code,
                'description'     => $r->product_description,
                'actual_cost'     => round((float)$r->actual_cost, 2),
                'expected_cost'   => round((float)$r->expected_cost, 2),
                'variance'        => round((float)$r->actual_cost - (float)$r->expected_cost, 2),
                'variance_pct'    => $r->expected_cost > 0
                    ? round(((float)$r->actual_cost - (float)$r->expected_cost) / (float)$r->expected_cost * 100, 1)
                    : null,
                'total_produced'  => round((float)$r->total_produced, 2),
            ]);

        // Top 5 products by production volume
        $topProducts = DB::table('work_orders')
            ->where('status', 'completed')
            ->whereBetween('completed_date', [$from, $to])
            ->selectRaw('product_code, product_description, SUM(actual_qty_produced) as total_produced, COUNT(*) as wo_count')
            ->groupBy('product_code', 'product_description')
            ->orderByDesc('total_produced')
            ->limit(5)
            ->get()
            ->map(fn($r) => [
                'product_code' => $r->product_code,
                'description'  => $r->product_description,
                'total_produced'=> round((float)$r->total_produced, 2),
                'wo_count'     => (int)$r->wo_count,
            ]);

        // Overall efficiency
        $totals = DB::table('work_orders')
            ->where('status', 'completed')
            ->whereBetween('completed_date', [$from, $to])
            ->selectRaw('SUM(actual_qty_produced) as actual, SUM(planned_qty) as planned, SUM(total_cost) as total_cost')
            ->first();

        $efficiencyPct = ($totals && $totals->planned > 0)
            ? round((float)$totals->actual / (float)$totals->planned * 100, 1)
            : 0;

        return ApiResponse::success([
            'period'        => ['from' => $from, 'to' => $to],
            'summary'       => [
                'total_produced'  => round((float)($totals->actual ?? 0), 2),
                'total_planned'   => round((float)($totals->planned ?? 0), 2),
                'efficiency_pct'  => $efficiencyPct,
                'total_cost'      => round((float)($totals->total_cost ?? 0), 2),
                'wo_count'        => $byStatus->sum('count'),
            ],
            'by_status'     => $byStatus->values(),
            'cycle_times'   => $cycleTimes->values(),
            'cost_variance' => $costVariance->values(),
            'top_products'  => $topProducts->values(),
        ], 'Manufacturing insights computed');
    }

    // ── Endpoint: AI advice via Groq ─────────────────────────────────────────

    public function advice(Request $request): JsonResponse
    {
        $to   = now()->toDateString();
        $from = now()->subDays(59)->toDateString();

        $totals = DB::table('work_orders')
            ->where('status', 'completed')
            ->whereBetween('completed_date', [$from, $to])
            ->selectRaw('SUM(actual_qty_produced) as actual, SUM(planned_qty) as planned, SUM(total_cost) as total_cost, COUNT(*) as wo_count')
            ->first();

        $effPct = ($totals && $totals->planned > 0)
            ? round((float)$totals->actual / (float)$totals->planned * 100, 1) : 0;

        $topProducts = DB::table('work_orders')
            ->where('status', 'completed')
            ->whereBetween('completed_date', [$from, $to])
            ->selectRaw('product_description, SUM(actual_qty_produced) as produced')
            ->groupBy('product_code', 'product_description')
            ->orderByDesc('produced')
            ->limit(5)
            ->get();

        $cycleTimes = DB::table('work_orders as wo')
            ->join('work_centres as wc', 'wc.id', '=', 'wo.work_centre_id')
            ->where('wo.status', 'completed')
            ->whereBetween('wo.completed_date', [$from, $to])
            ->selectRaw('wc.name, AVG(DATEDIFF(wo.completed_date, wo.start_date)) as avg_days')
            ->groupBy('wc.id', 'wc.name')
            ->orderByDesc('avg_days')
            ->limit(3)
            ->get();

        $actual    = round((float)($totals->actual ?? 0), 2);
        $planned   = round((float)($totals->planned ?? 0), 2);
        $cost      = round((float)($totals->total_cost ?? 0), 2);
        $woCount   = (int)($totals->wo_count ?? 0);
        $prodList  = $topProducts->map(fn($p) => "  - {$p->product_description}: " . round((float)$p->produced, 2) . " units")->implode("\n");
        $cycleList = $cycleTimes->map(fn($c) => "  - {$c->name}: " . round((float)$c->avg_days, 1) . " days avg")->implode("\n");

        $prompt = <<<PROMPT
You are a manufacturing operations analyst for a Kenyan agricultural processing ERP. Analyze the following 60-day production data and provide structured, actionable advice. Currency is KES.

MANUFACTURING DATA (last 60 days):
- Work orders completed: {$woCount}
- Total quantity produced: {$actual} units (planned: {$planned} units)
- Production efficiency: {$effPct}%
- Total production cost: KES {$cost}
- Top products by output:
{$prodList}
- Work centre average cycle times:
{$cycleList}

Respond ONLY with a valid JSON object (no markdown, no extra text) with this exact structure:
{
  "headline": "One-sentence manufacturing performance assessment",
  "score": <integer 1-10>,
  "capacity_planning": {
    "insight": "What current utilization and efficiency trends indicate",
    "recommendation": "Specific capacity or scheduling action"
  },
  "efficiency_improvement": {
    "insight": "Where production bottlenecks or inefficiencies exist",
    "recommendation": "Specific efficiency improvement step"
  },
  "cost_reduction": {
    "insight": "Where production costs deviate from expectations",
    "recommendation": "Specific cost reduction action"
  },
  "quality_control": {
    "insight": "What the gap between planned and actual output suggests",
    "recommendation": "Specific quality or process control action"
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
        ], 'Manufacturing AI advice generated');
    }
}
