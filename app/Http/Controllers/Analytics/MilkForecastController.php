<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class MilkForecastController extends Controller
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Simple linear regression. Returns slope, intercept, and R². */
    private function linearRegression(array $y): array
    {
        $n = count($y);
        if ($n < 2) {
            return ['slope' => 0, 'intercept' => $y[0] ?? 0, 'r2' => 0];
        }
        $x     = range(0, $n - 1);
        $sumX  = array_sum($x);
        $sumY  = array_sum($y);
        $sumXY = 0;
        $sumX2 = 0;
        foreach ($x as $i => $xi) {
            $sumXY += $xi * $y[$i];
            $sumX2 += $xi * $xi;
        }
        $denom    = $n * $sumX2 - $sumX * $sumX;
        $slope    = $denom != 0 ? ($n * $sumXY - $sumX * $sumY) / $denom : 0;
        $intercept = ($sumY - $slope * $sumX) / $n;

        // R²
        $yMean = $sumY / $n;
        $ssTot = 0;
        $ssRes = 0;
        foreach ($y as $i => $yi) {
            $yHat   = $slope * $i + $intercept;
            $ssTot += ($yi - $yMean) ** 2;
            $ssRes += ($yi - $yHat) ** 2;
        }
        $r2 = $ssTot > 0 ? 1 - $ssRes / $ssTot : 0;

        return ['slope' => $slope, 'intercept' => $intercept, 'r2' => round($r2, 4)];
    }

    /** Day-of-week seasonal factors (0=Sun … 6=Sat), normalised around 1.0. */
    private function seasonalFactors(array $dailyRows): array
    {
        $buckets = array_fill(0, 7, ['sum' => 0, 'count' => 0]);
        foreach ($dailyRows as $row) {
            $dow = Carbon::parse($row['date'])->dayOfWeek;
            $buckets[$dow]['sum']   += $row['qty'];
            $buckets[$dow]['count'] += 1;
        }
        $avgs  = [];
        $grand = 0;
        foreach ($buckets as $dow => $b) {
            $avgs[$dow] = $b['count'] > 0 ? $b['sum'] / $b['count'] : 0;
            $grand     += $avgs[$dow];
        }
        $mean = $grand / 7;
        $factors = [];
        foreach ($avgs as $dow => $avg) {
            $factors[$dow] = $mean > 0 ? $avg / $mean : 1.0;
        }
        return $factors;
    }

    /** Mean Absolute Error of linear-fit residuals. */
    private function mae(array $y, float $slope, float $intercept): float
    {
        $n = count($y);
        if ($n === 0) return 0;
        $sum = 0;
        foreach ($y as $i => $yi) {
            $sum += abs($yi - ($slope * $i + $intercept));
        }
        return $sum / $n;
    }

    /** 7-day rolling average over an array of values (right-aligned). */
    private function movingAvg(array $values, int $window = 7): array
    {
        $result = [];
        foreach ($values as $i => $v) {
            $start   = max(0, $i - $window + 1);
            $slice   = array_slice($values, $start, $i - $start + 1);
            $result[] = count($slice) ? array_sum($slice) / count($slice) : 0;
        }
        return $result;
    }

    // ── Endpoint: forecast ────────────────────────────────────────────────────

    public function forecast(Request $request): JsonResponse
    {
        $horizonDays = (int) $request->get('horizon', 30);   // days to predict
        $histDays    = (int) $request->get('history', 90);   // days of history

        $histFrom = now()->subDays($histDays - 1)->toDateString();
        $histTo   = now()->toDateString();

        // ── Historical daily totals ──────────────────────────────────────────
        $rawRows = DB::table('milk_grader_collections')
            ->whereBetween('date_collected', [$histFrom, $histTo])
            ->selectRaw('date_collected AS date, SUM(quantity) AS qty, AVG(rate) AS avg_rate')
            ->groupBy('date_collected')
            ->orderBy('date_collected')
            ->get()
            ->keyBy('date');

        // Fill every calendar day (missing = 0)
        $allDates  = [];
        $cur       = Carbon::parse($histFrom);
        $end       = Carbon::parse($histTo);
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
        $seasonal  = $this->seasonalFactors($allDates);
        $mae       = $this->mae($qtyValues, $reg['slope'], $reg['intercept']);
        $movAvg    = $this->movingAvg($qtyValues);

        // ── Attach moving average to historical ──────────────────────────────
        foreach ($allDates as $i => &$row) {
            $row['ma7']      = round($movAvg[$i], 1);
            $row['trend_val'] = round($reg['slope'] * $i + $reg['intercept'], 1);
        }
        unset($row);

        // ── Future predictions ───────────────────────────────────────────────
        $n          = count($allDates);
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

        // ── Trend classification ─────────────────────────────────────────────
        $dailyChange  = $reg['slope'];
        $baseLevel    = $reg['intercept'] + $reg['slope'] * ($n / 2);
        $trendPct     = $baseLevel > 0 ? round(($dailyChange / $baseLevel) * 100, 2) : 0;
        $trendLabel   = abs($trendPct) < 0.3 ? 'stable'
            : ($trendPct > 0 ? 'growing' : 'declining');

        // Coefficient of variation
        $mean = count($qtyValues) ? array_sum($qtyValues) / count($qtyValues) : 0;
        $variance = 0;
        foreach ($qtyValues as $v) { $variance += ($v - $mean) ** 2; }
        $std = $mean > 0 ? sqrt($variance / count($qtyValues)) : 0;
        $cv  = $mean > 0 ? round($std / $mean * 100, 1) : 0;

        // Projected next-period total
        $projTotal30 = round(array_sum(array_column(array_slice($predictions, 0, 30), 'qty')), 1);

        return ApiResponse::success([
            'history'      => $allDates,
            'predictions'  => $predictions,
            'model'        => [
                'slope'        => round($reg['slope'], 4),
                'intercept'    => round($reg['intercept'], 2),
                'r2'           => $reg['r2'],
                'mae'          => round($mae, 1),
                'trend'        => $trendLabel,
                'trend_pct'    => $trendPct,
                'cv'           => $cv,
                'proj_30d'     => $projTotal30,
            ],
        ], 'Forecast computed');
    }

    // ── Endpoint: insights ────────────────────────────────────────────────────

    public function insights(Request $request): JsonResponse
    {
        $to   = now()->toDateString();
        $from = now()->subDays(59)->toDateString();   // 60-day window
        $prev = now()->subDays(119)->toDateString();  // prior 60-day window

        // ── Current & prior period totals ────────────────────────────────────
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

        // ── Top graders by volume & trend ────────────────────────────────────
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
                'code'    => $g->code,
                'qty'     => round((float)$g->qty, 1),
                'amount'  => round((float)$g->amount, 2),
                'days'    => (int)$g->days,
                'share'   => $curQty > 0 ? round((float)$g->qty / $curQty * 100, 1) : 0,
                'growth'  => $growth,
                'trend'   => $growth === null ? 'new'
                    : (abs($growth) < 5 ? 'stable' : ($growth > 0 ? 'growing' : 'declining')),
            ];
        });

        // ── Best day of week ─────────────────────────────────────────────────
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

        // ── Farmer activity ──────────────────────────────────────────────────
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

        // ── Zero-collection days ─────────────────────────────────────────────
        $activeDays    = (int)($current->days ?? 0);
        $calendarDays  = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
        $missingDays   = $calendarDays - $activeDays;

        // ── Anomalies (days > 2 std dev from mean) ───────────────────────────
        $dailyQtys = DB::table('milk_grader_collections')
            ->whereBetween('date_collected', [$from, $to])
            ->selectRaw('date_collected AS date, SUM(quantity) AS qty')
            ->groupBy('date_collected')
            ->orderBy('date_collected')
            ->get();

        $vals  = $dailyQtys->pluck('qty')->map(fn($v) => (float)$v)->toArray();
        $mean  = count($vals) ? array_sum($vals) / count($vals) : 0;
        $var   = 0;
        foreach ($vals as $v) { $var += ($v - $mean) ** 2; }
        $std   = count($vals) > 1 ? sqrt($var / count($vals)) : 0;
        $anomalies = $dailyQtys->filter(function ($r) use ($mean, $std) {
            return $std > 0 && abs((float)$r->qty - $mean) > 2 * $std;
        })->values()->map(fn($r) => [
            'date' => $r->date,
            'qty'  => round((float)$r->qty, 1),
            'type' => (float)$r->qty > $mean ? 'spike' : 'drop',
        ]);

        return ApiResponse::success([
            'period'       => ['from' => $from, 'to' => $to],
            'summary'      => [
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
            'graders'      => $graderStats->values(),
            'by_dow'       => $byDow->values(),
            'anomalies'    => $anomalies->values(),
        ], 'Insights computed');
    }

    // ── Endpoint: AI advice via Groq ─────────────────────────────────────────

    public function advice(Request $request): JsonResponse
    {
        $apiKey = config('services.groq.key');
        if (! $apiKey) {
            return ApiResponse::success([
                'error'   => 'no_key',
                'message' => 'GROQ_API_KEY is not configured. Add it to .env to enable AI advice.',
            ], 'No API key');
        }

        // Pull summary metrics to build the prompt
        $to   = now()->toDateString();
        $from = now()->subDays(59)->toDateString();
        $prev = now()->subDays(119)->toDateString();
        $prevTo = now()->subDays(60)->toDateString();

        $cur  = DB::table('milk_grader_collections')
            ->whereBetween('date_collected', [$from, $to])
            ->selectRaw('SUM(quantity) AS qty, SUM(quantity*rate) AS amount, AVG(rate) AS avg_rate, COUNT(DISTINCT date_collected) AS days')
            ->first();

        $prv  = DB::table('milk_grader_collections')
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

        $curQty = round((float)($cur->qty ?? 0), 1);
        $curAmt = round((float)($cur->amount ?? 0), 2);
        $prvQty = round((float)($prv->qty ?? 0), 1);
        $prvAmt = round((float)($prv->amount ?? 0), 2);
        $rpl    = $curQty > 0 ? round($curAmt / $curQty, 4) : 0;
        $avgRate = round((float)($cur->avg_rate ?? 0), 4);
        $days   = (int)($cur->days ?? 0);
        $calDays = 60;

        $graderList = $topGraders->map(fn($g) => "  - {$g->location}: " . round((float)$g->qty, 1) . " L")->implode("\n");

        $growthQtyPct = $prvQty > 0 ? round(($curQty - $prvQty) / $prvQty * 100, 1) : 'N/A';
        $growthAmtPct = $prvAmt > 0 ? round(($curAmt - $prvAmt) / $prvAmt * 100, 1) : 'N/A';

        $prompt = <<<PROMPT
You are a dairy cooperative business analyst. Analyze the following 60-day operational data from a milk collection ERP system and provide structured, actionable business advice.

OPERATIONAL DATA (last 60 days vs prior 60 days):
- Total milk collected: {$curQty} L (vs {$prvQty} L prior period, change: {$growthQtyPct}%)
- Total revenue: KES {$curAmt} (vs KES {$prvAmt}, change: {$growthAmtPct}%)
- Average buying rate: KES {$avgRate}/L
- Revenue per litre collected: KES {$rpl}
- Active collection days: {$days} of {$calDays} calendar days
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

        $response = Http::timeout(30)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ])
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model'       => 'llama-3.3-70b-versatile',
                'max_tokens'  => 1200,
                'temperature' => 0.3,
                'messages'    => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if (! $response->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Groq API error: ' . $response->status(),
            ], 502);
        }

        $raw = $response->json('choices.0.message.content', '{}');

        // Strip markdown fences if model wrapped in them anyway
        $raw = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $raw = preg_replace('/```\s*$/m', '', $raw);

        $advice = json_decode(trim($raw), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json([
                'success' => false,
                'message' => 'Could not parse Groq response as JSON.',
                'raw'     => $raw,
            ], 500);
        }

        return ApiResponse::success([
            'advice'    => $advice,
            'generated' => now()->toISOString(),
        ], 'AI advice generated');
    }
}
