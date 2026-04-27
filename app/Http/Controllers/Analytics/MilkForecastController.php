<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Traits\StatisticalAnalysisTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MilkForecastController extends Controller
{
    use StatisticalAnalysisTrait;

    // ── Endpoint: forecast ────────────────────────────────────────────────────

    public function forecast(Request $request): JsonResponse
    {
        $horizonDays = (int) $request->get('horizon', 30);
        $histDays    = (int) $request->get('history', 90);

        $histFrom = now()->subDays($histDays - 1)->toDateString();
        $histTo   = now()->toDateString();

        $rawRows = DB::table('milk_grader_collections')
            ->whereBetween('date_collected', [$histFrom, $histTo])
            ->selectRaw('date_collected AS date, SUM(quantity) AS qty, AVG(rate) AS avg_rate')
            ->groupBy('date_collected')
            ->orderBy('date_collected')
            ->get()
            ->keyBy('date');

        // Fill every calendar day (missing = 0)
        $allDates = [];
        $cur      = Carbon::parse($histFrom);
        $end      = Carbon::parse($histTo);
        while ($cur->lte($end)) {
            $d   = $cur->toDateString();
            $row = $rawRows->get($d);
            $allDates[] = [
                'date'     => $d,
                'qty'      => $row ? round((float) $row->qty, 1) : 0,
                'avg_rate' => $row ? round((float) $row->avg_rate, 2) : null,
            ];
            $cur->addDay();
        }

        $qtyValues = array_column($allDates, 'qty');
        $reg       = $this->linearRegression($qtyValues);
        $seasonal  = $this->seasonalFactors($allDates, 'date', 'qty');
        $mae       = $this->mae($qtyValues, $reg['slope'], $reg['intercept']);
        $movAvg    = $this->movingAvg($qtyValues);

        foreach ($allDates as $i => &$row) {
            $row['ma7']       = round($movAvg[$i], 1);
            $row['trend_val'] = round($reg['slope'] * $i + $reg['intercept'], 1);
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
                'date'  => $futureDate->toDateString(),
                'qty'   => round($predicted, 1),
                'lower' => round(max(0, $predicted - 1.5 * $mae), 1),
                'upper' => round($predicted + 1.5 * $mae, 1),
            ];
        }

        $trend       = $this->classifyTrend($reg['slope'], $reg['intercept'], $n);
        $mean        = count($qtyValues) ? array_sum($qtyValues) / count($qtyValues) : 0;
        $variance    = 0;
        foreach ($qtyValues as $v) { $variance += ($v - $mean) ** 2; }
        $std         = $mean > 0 ? sqrt($variance / count($qtyValues)) : 0;
        $cv          = $mean > 0 ? round($std / $mean * 100, 1) : 0;
        $projTotal30 = round(array_sum(array_column(array_slice($predictions, 0, 30), 'qty')), 1);

        return ApiResponse::success([
            'history'     => $allDates,
            'predictions' => $predictions,
            'model'       => [
                'slope'     => round($reg['slope'], 4),
                'intercept' => round($reg['intercept'], 2),
                'r2'        => $reg['r2'],
                'mae'       => round($mae, 1),
                'trend'     => $trend['label'],
                'trend_pct' => $trend['pct'],
                'cv'        => $cv,
                'proj_30d'  => $projTotal30,
            ],
        ], 'Forecast computed');
    }

    // ── Endpoint: insights ────────────────────────────────────────────────────

    public function insights(Request $request): JsonResponse
    {
        $to   = now()->toDateString();
        $from = now()->subDays(59)->toDateString();
        $prev = now()->subDays(119)->toDateString();

        $current = DB::table('milk_grader_collections')
            ->whereBetween('date_collected', [$from, $to])
            ->selectRaw('SUM(quantity) AS qty, SUM(quantity*rate) AS amount, AVG(rate) AS avg_rate, COUNT(DISTINCT date_collected) AS days')
            ->first();

        $prior = DB::table('milk_grader_collections')
            ->whereBetween('date_collected', [$prev, now()->subDays(60)->toDateString()])
            ->selectRaw('SUM(quantity) AS qty, SUM(quantity*rate) AS amount, AVG(rate) AS avg_rate, COUNT(DISTINCT date_collected) AS days')
            ->first();

        $curQty  = (float)($current->qty    ?? 0);
        $curAmt  = (float)($current->amount ?? 0);
        $prQty   = (float)($prior->qty      ?? 0);
        $prAmt   = (float)($prior->amount   ?? 0);
        $growthQty = $prQty  > 0 ? round(($curQty - $prQty)  / $prQty  * 100, 1) : null;
        $growthAmt = $prAmt  > 0 ? round(($curAmt - $prAmt)  / $prAmt  * 100, 1) : null;
        $rplCurrent = $curQty > 0 ? round($curAmt / $curQty, 2) : 0;
        $rplPrior   = $prQty  > 0 ? round($prAmt  / $prQty,  2) : 0;
        $rplTrend   = $rplPrior > 0 ? round(($rplCurrent - $rplPrior) / $rplPrior * 100, 1) : null;

        $graders = DB::table('milk_grader_collections as gc')
            ->whereBetween('gc.date_collected', [$from, $to])
            ->selectRaw('gc.location AS code, SUM(gc.quantity) AS qty, SUM(gc.quantity*gc.rate) AS amount, COUNT(DISTINCT gc.date_collected) AS days')
            ->groupBy('gc.location')
            ->orderByDesc('qty')
            ->limit(10)
            ->get();

        $gradersPrev = DB::table('milk_grader_collections as gc')
            ->whereBetween('gc.date_collected', [$prev, now()->subDays(60)->toDateString()])
            ->selectRaw('gc.location AS code, SUM(gc.quantity) AS qty_prev')
            ->groupBy('gc.location')
            ->get()
            ->keyBy('code');

        $graderStats = $graders->map(function ($g) use ($gradersPrev, $curQty) {
            $prev    = $gradersPrev->get($g->code);
            $prevQty = $prev ? (float)$prev->qty_prev : 0;
            $growth  = $prevQty > 0 ? round(((float)$g->qty - $prevQty) / $prevQty * 100, 1) : null;
            return [
                'code'   => $g->code,
                'qty'    => round((float)$g->qty, 1),
                'amount' => round((float)$g->amount, 2),
                'days'   => (int)$g->days,
                'share'  => $curQty > 0 ? round((float)$g->qty / $curQty * 100, 1) : 0,
                'growth' => $growth,
                'trend'  => $growth === null ? 'new'
                    : (abs($growth) < 5 ? 'stable' : ($growth > 0 ? 'growing' : 'declining')),
            ];
        });

        $byDow = DB::table('milk_grader_collections')
            ->whereBetween('date_collected', [$from, $to])
            ->selectRaw('DAYOFWEEK(date_collected) AS dow, AVG(quantity) AS avg_qty, COUNT(DISTINCT date_collected) AS days')
            ->groupBy(DB::raw('DAYOFWEEK(date_collected)'))
            ->orderByDesc('avg_qty')
            ->get()
            ->map(fn($r) => [
                'dow'     => (int)$r->dow,
                'label'   => ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][$r->dow - 1] ?? '?',
                'avg_qty' => round((float)$r->avg_qty, 1),
                'days'    => (int)$r->days,
            ]);

        $farmersCurrent = DB::table('milk_grader_collections as gc')
            ->join('milk_purchases as mp', 'mp.id', '=', 'gc.purchase_id')
            ->join('milk_purchase_items as mpi', 'mpi.purchase_id', '=', 'mp.id')
            ->whereBetween('gc.date_collected', [$from, $to])
            ->whereNotNull('mpi.farmer_id')
            ->distinct()
            ->count('mpi.farmer_id');

        $farmersPrior = DB::table('milk_grader_collections as gc')
            ->join('milk_purchases as mp', 'mp.id', '=', 'gc.purchase_id')
            ->join('milk_purchase_items as mpi', 'mpi.purchase_id', '=', 'mp.id')
            ->whereBetween('gc.date_collected', [$prev, now()->subDays(60)->toDateString()])
            ->whereNotNull('mpi.farmer_id')
            ->distinct()
            ->count('mpi.farmer_id');

        $farmerGrowth = $farmersPrior > 0
            ? round(($farmersCurrent - $farmersPrior) / $farmersPrior * 100, 1)
            : null;

        $activeDays   = (int)($current->days ?? 0);
        $calendarDays = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $missingDays  = $calendarDays - $activeDays;

        $dailyQtys = DB::table('milk_grader_collections')
            ->whereBetween('date_collected', [$from, $to])
            ->selectRaw('date_collected AS date, SUM(quantity) AS qty')
            ->groupBy('date_collected')
            ->orderBy('date_collected')
            ->get()
            ->toArray();

        $vals      = array_map(fn($r) => (float)$r->qty, $dailyQtys);
        $anomalies = $this->detectAnomalies($vals, array_map('get_object_vars', $dailyQtys), 'date', 'qty');

        return ApiResponse::success([
            'period'    => ['from' => $from, 'to' => $to],
            'summary'   => [
                'qty'           => round($curQty, 1),
                'amount'        => round($curAmt, 2),
                'growth_qty'    => $growthQty,
                'growth_amount' => $growthAmt,
                'rpl'           => $rplCurrent,
                'rpl_trend'     => $rplTrend,
                'active_days'   => $activeDays,
                'missing_days'  => $missingDays,
                'farmers'       => $farmersCurrent,
                'farmer_growth' => $farmerGrowth,
            ],
            'graders'   => $graderStats->values(),
            'by_dow'    => $byDow->values(),
            'anomalies' => $anomalies,
        ], 'Insights computed');
    }

    // ── Endpoint: AI advice via Groq ─────────────────────────────────────────

    public function advice(Request $request): JsonResponse
    {
        $to      = now()->toDateString();
        $from    = now()->subDays(59)->toDateString();
        $prev    = now()->subDays(119)->toDateString();
        $prevTo  = now()->subDays(60)->toDateString();

        $cur = DB::table('milk_grader_collections')
            ->whereBetween('date_collected', [$from, $to])
            ->selectRaw('SUM(quantity) AS qty, SUM(quantity*rate) AS amount, AVG(rate) AS avg_rate, COUNT(DISTINCT date_collected) AS days')
            ->first();

        $prv = DB::table('milk_grader_collections')
            ->whereBetween('date_collected', [$prev, $prevTo])
            ->selectRaw('SUM(quantity) AS qty, SUM(quantity*rate) AS amount, AVG(rate) AS avg_rate')
            ->first();

        $topGraders = DB::table('milk_grader_collections')
            ->whereBetween('date_collected', [$from, $to])
            ->selectRaw('location, SUM(quantity) AS qty')
            ->groupBy('location')
            ->orderByDesc('qty')
            ->limit(5)
            ->get();

        $curQty = round((float)($cur->qty    ?? 0), 1);
        $curAmt = round((float)($cur->amount ?? 0), 2);
        $prvQty = round((float)($prv->qty    ?? 0), 1);
        $prvAmt = round((float)($prv->amount ?? 0), 2);
        $rpl    = $curQty > 0 ? round($curAmt / $curQty, 4) : 0;
        $avgRate = round((float)($cur->avg_rate ?? 0), 4);
        $days   = (int)($cur->days ?? 0);

        $graderList   = $topGraders->map(fn($g) => "  - {$g->location}: " . round((float)$g->qty, 1) . " L")->implode("\n");
        $growthQtyPct = $prvQty > 0 ? round(($curQty - $prvQty) / $prvQty * 100, 1) : 'N/A';
        $growthAmtPct = $prvAmt > 0 ? round(($curAmt - $prvAmt) / $prvAmt * 100, 1) : 'N/A';

        $prompt = <<<PROMPT
You are a dairy cooperative business analyst. Analyze the following 60-day operational data from a milk collection ERP system and provide structured, actionable business advice.

OPERATIONAL DATA (last 60 days vs prior 60 days):
- Total milk collected: {$curQty} L (vs {$prvQty} L prior period, change: {$growthQtyPct}%)
- Total revenue: KES {$curAmt} (vs KES {$prvAmt}, change: {$growthAmtPct}%)
- Average buying rate: KES {$avgRate}/L
- Revenue per litre collected: KES {$rpl}
- Active collection days: {$days} of 60 calendar days
- Top graders by volume:
{$graderList}

Respond ONLY with a valid JSON object (no markdown, no extra text) with this exact structure:
{
  "headline": "One-sentence overall health assessment",
  "score": <integer 1-10 representing business health>,
  "pricing": {
    "insight": "What the current rate/revenue-per-litre data tells us",
    "recommendation": "Specific pricing action to take"
  },
  "operations": {
    "insight": "What collection day gaps and grader concentration tell us",
    "recommendation": "Specific operational improvement"
  },
  "farmers": {
    "insight": "Supply base analysis based on available data",
    "recommendation": "Farmer engagement action"
  },
  "revenue": {
    "insight": "Revenue trend and efficiency observation",
    "recommendation": "Revenue growth action"
  },
  "risks": ["risk 1", "risk 2", "risk 3"],
  "opportunities": ["opportunity 1", "opportunity 2", "opportunity 3"],
  "quick_wins": ["actionable item in < 30 days", "second quick win"]
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
                'message' => 'GROQ_API_KEY is not configured. Add it to .env to enable AI advice.',
            ], 'No API key');
        }

        return ApiResponse::success([
            'advice'    => $advice,
            'generated' => now()->toISOString(),
        ], 'AI advice generated');
    }
}
