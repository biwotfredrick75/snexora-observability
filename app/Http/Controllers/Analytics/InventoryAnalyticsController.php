<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Traits\StatisticalAnalysisTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryAnalyticsController extends Controller
{
    use StatisticalAnalysisTrait;

    // ── Endpoint: forecast ────────────────────────────────────────────────────

    public function forecast(Request $request): JsonResponse
    {
        $horizonDays = (int) $request->get('horizon', 30);
        $histDays    = (int) $request->get('history', 90);

        $histFrom = now()->subDays($histDays - 1)->toDateString();
        $histTo   = now()->toDateString();

        // Outbound consumption movements (types 10=invoice, 26=delivery; negative qty)
        $rawRows = DB::table('stock_movements')
            ->whereBetween('tran_date', [$histFrom, $histTo])
            ->whereIn('type', [10, 26])
            ->where('qty', '<', 0)
            ->selectRaw('DATE(tran_date) AS date, SUM(ABS(qty) * price) AS value, SUM(ABS(qty)) AS total_qty')
            ->groupBy(DB::raw('DATE(tran_date)'))
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
                'date'      => $d,
                'value'     => $row ? round((float) $row->value, 2) : 0,
                'total_qty' => $row ? round((float) $row->total_qty, 2) : 0,
            ];
            $cur->addDay();
        }

        $values   = array_column($allDates, 'value');
        $reg      = $this->linearRegression($values);
        $seasonal = $this->seasonalFactors($allDates, 'date', 'value');
        $mae      = $this->mae($values, $reg['slope'], $reg['intercept']);
        $movAvg   = $this->movingAvg($values);

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
                'date'  => $futureDate->toDateString(),
                'value' => round($predicted, 2),
                'lower' => round(max(0, $predicted - 1.5 * $mae), 2),
                'upper' => round($predicted + 1.5 * $mae, 2),
            ];
        }

        $trend   = $this->classifyTrend($reg['slope'], $reg['intercept'], $n);
        $mean    = count($values) ? array_sum($values) / count($values) : 0;
        $variance = 0;
        foreach ($values as $v) { $variance += ($v - $mean) ** 2; }
        $std     = count($values) > 1 ? sqrt($variance / count($values)) : 0;
        $cv      = $mean > 0 ? round($std / $mean * 100, 1) : 0;
        $proj30d = round(array_sum(array_column(array_slice($predictions, 0, 30), 'value')), 2);

        // Items below reorder level
        $reorderAlerts = DB::table('stock_movements as sm')
            ->join('item_reorder_levels as rl', 'rl.stock_id', '=', 'sm.stock_id')
            ->join('items as i', 'i.stock_id', '=', 'sm.stock_id')
            ->select('sm.stock_id', 'i.description', DB::raw('SUM(sm.qty) as soh'), 'rl.reorder_level')
            ->groupBy('sm.stock_id', 'i.description', 'rl.reorder_level')
            ->havingRaw('SUM(sm.qty) < rl.reorder_level')
            ->orderByRaw('(SUM(sm.qty) - rl.reorder_level)')
            ->limit(20)
            ->get()
            ->map(fn($r) => [
                'stock_id'      => $r->stock_id,
                'description'   => $r->description,
                'soh'           => round((float)$r->soh, 2),
                'reorder_level' => (float)$r->reorder_level,
                'deficit'       => round((float)$r->reorder_level - (float)$r->soh, 2),
            ]);

        return ApiResponse::success([
            'history'        => $allDates,
            'predictions'    => $predictions,
            'model'          => [
                'slope'     => round($reg['slope'], 4),
                'intercept' => round($reg['intercept'], 2),
                'r2'        => $reg['r2'],
                'mae'       => round($mae, 2),
                'trend'     => $trend['label'],
                'trend_pct' => $trend['pct'],
                'cv'        => $cv,
                'proj_30d'  => $proj30d,
            ],
            'reorder_alerts' => $reorderAlerts->values(),
        ], 'Inventory forecast computed');
    }

    // ── Endpoint: insights ────────────────────────────────────────────────────

    public function insights(Request $request): JsonResponse
    {
        $to   = now()->toDateString();
        $from = now()->subDays(59)->toDateString();

        // Stock value by category
        $byCategory = DB::table('stock_movements as sm')
            ->join('items as i', 'i.stock_id', '=', 'sm.stock_id')
            ->join('item_categories as ic', 'ic.id', '=', 'i.category_id')
            ->select('ic.id', 'ic.name as category', DB::raw('SUM(sm.qty * sm.price) as stock_value'), DB::raw('SUM(sm.qty) as total_qty'))
            ->groupBy('ic.id', 'ic.name')
            ->orderByDesc('stock_value')
            ->get()
            ->map(fn($r) => [
                'category'    => $r->category,
                'stock_value' => round((float)$r->stock_value, 2),
                'total_qty'   => round((float)$r->total_qty, 2),
            ]);

        $totalStockValue = $byCategory->sum('stock_value');

        // Fast movers: top 10 by outbound qty in 60 days
        $fastMovers = DB::table('stock_movements as sm')
            ->join('items as i', 'i.stock_id', '=', 'sm.stock_id')
            ->join('item_categories as ic', 'ic.id', '=', 'i.category_id')
            ->whereBetween('sm.tran_date', [$from, $to])
            ->whereIn('sm.type', [10, 26])
            ->where('sm.qty', '<', 0)
            ->selectRaw('sm.stock_id, i.description, ic.name as category,
                         SUM(ABS(sm.qty)) as total_qty, SUM(ABS(sm.qty) * sm.price) as total_value')
            ->groupBy('sm.stock_id', 'i.description', 'ic.name')
            ->orderByDesc('total_qty')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'stock_id'    => $r->stock_id,
                'description' => $r->description,
                'category'    => $r->category,
                'total_qty'   => round((float)$r->total_qty, 2),
                'total_value' => round((float)$r->total_value, 2),
            ]);

        // Slow movers: lowest outbound qty in 60 days (items that had any movement)
        $slowMovers = DB::table('stock_movements as sm')
            ->join('items as i', 'i.stock_id', '=', 'sm.stock_id')
            ->join('item_categories as ic', 'ic.id', '=', 'i.category_id')
            ->whereBetween('sm.tran_date', [$from, $to])
            ->whereIn('sm.type', [10, 26])
            ->where('sm.qty', '<', 0)
            ->selectRaw('sm.stock_id, i.description, ic.name as category,
                         SUM(ABS(sm.qty)) as total_qty, SUM(ABS(sm.qty) * sm.price) as total_value')
            ->groupBy('sm.stock_id', 'i.description', 'ic.name')
            ->orderBy('total_qty')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'stock_id'    => $r->stock_id,
                'description' => $r->description,
                'category'    => $r->category,
                'total_qty'   => round((float)$r->total_qty, 2),
                'total_value' => round((float)$r->total_value, 2),
            ]);

        // Dead stock: positive SOH, no outbound movement in 60 days
        $cutoff   = now()->subDays(60)->toDateString();
        $deadStock = DB::table('items as i')
            ->leftJoin(DB::raw(
                "(SELECT stock_id, MAX(tran_date) as last_movement
                  FROM stock_movements
                  WHERE type IN (10,26) AND qty < 0
                  GROUP BY stock_id) AS lm"
            ), 'lm.stock_id', '=', 'i.stock_id')
            ->join('item_categories as ic', 'ic.id', '=', 'i.category_id')
            ->where('i.inactive', 0)
            ->where(fn($q) => $q->whereNull('lm.last_movement')
                ->orWhere('lm.last_movement', '<', $cutoff))
            ->selectRaw('i.stock_id, i.description, ic.name as category, lm.last_movement,
                         (SELECT SUM(qty) FROM stock_movements WHERE stock_id = i.stock_id) as soh')
            ->having('soh', '>', 0)
            ->orderByRaw('lm.last_movement ASC')
            ->limit(20)
            ->get()
            ->map(fn($r) => [
                'stock_id'      => $r->stock_id,
                'description'   => $r->description,
                'category'      => $r->category,
                'soh'           => round((float)$r->soh, 2),
                'last_movement' => $r->last_movement,
            ]);

        // Reorder alerts count
        $reorderCount = DB::table('stock_movements as sm')
            ->join('item_reorder_levels as rl', 'rl.stock_id', '=', 'sm.stock_id')
            ->select('sm.stock_id', DB::raw('SUM(sm.qty) as soh'), 'rl.reorder_level')
            ->groupBy('sm.stock_id', 'rl.reorder_level')
            ->havingRaw('SUM(sm.qty) < rl.reorder_level')
            ->count();

        return ApiResponse::success([
            'period'        => ['from' => $from, 'to' => $to],
            'summary'       => [
                'total_stock_value' => round($totalStockValue, 2),
                'category_count'    => $byCategory->count(),
                'reorder_alerts'    => $reorderCount,
                'dead_stock_count'  => $deadStock->count(),
                'fast_mover_count'  => $fastMovers->count(),
            ],
            'by_category'   => $byCategory->values(),
            'fast_movers'   => $fastMovers->values(),
            'slow_movers'   => $slowMovers->values(),
            'dead_stock'    => $deadStock->values(),
        ], 'Inventory insights computed');
    }

    // ── Endpoint: AI advice via Groq ─────────────────────────────────────────

    public function advice(Request $request): JsonResponse
    {
        $to   = now()->toDateString();
        $from = now()->subDays(59)->toDateString();

        $byCategory = DB::table('stock_movements as sm')
            ->join('items as i', 'i.stock_id', '=', 'sm.stock_id')
            ->join('item_categories as ic', 'ic.id', '=', 'i.category_id')
            ->select('ic.name as category', DB::raw('SUM(sm.qty * sm.price) as stock_value'))
            ->groupBy('ic.id', 'ic.name')
            ->orderByDesc('stock_value')
            ->limit(5)
            ->get();

        $reorderCount = DB::table('stock_movements as sm')
            ->join('item_reorder_levels as rl', 'rl.stock_id', '=', 'sm.stock_id')
            ->select('sm.stock_id', DB::raw('SUM(sm.qty) as soh'), 'rl.reorder_level')
            ->groupBy('sm.stock_id', 'rl.reorder_level')
            ->havingRaw('SUM(sm.qty) < rl.reorder_level')
            ->count();

        $cutoff    = now()->subDays(60)->toDateString();
        $deadCount = DB::table('items as i')
            ->leftJoin(DB::raw(
                "(SELECT stock_id, MAX(tran_date) as last_movement
                  FROM stock_movements WHERE type IN (10,26) AND qty < 0
                  GROUP BY stock_id) AS lm"
            ), 'lm.stock_id', '=', 'i.stock_id')
            ->where('i.inactive', 0)
            ->where(fn($q) => $q->whereNull('lm.last_movement')->orWhere('lm.last_movement', '<', $cutoff))
            ->having(DB::raw('(SELECT SUM(qty) FROM stock_movements WHERE stock_id = i.stock_id)'), '>', 0)
            ->count();

        $fastMovers = DB::table('stock_movements as sm')
            ->join('items as i', 'i.stock_id', '=', 'sm.stock_id')
            ->whereBetween('sm.tran_date', [$from, $to])
            ->whereIn('sm.type', [10, 26])
            ->where('sm.qty', '<', 0)
            ->selectRaw('i.description, SUM(ABS(sm.qty)) as total_qty')
            ->groupBy('sm.stock_id', 'i.description')
            ->orderByDesc('total_qty')
            ->limit(5)
            ->get();

        $totalValue  = round($byCategory->sum('stock_value'), 2);
        $catList     = $byCategory->map(fn($c) => "  - {$c->category}: KES " . number_format((float)$c->stock_value, 2))->implode("\n");
        $fastList    = $fastMovers->map(fn($i) => "  - {$i->description}: " . round((float)$i->total_qty, 1) . " units")->implode("\n");

        $prompt = <<<PROMPT
You are a supply chain and inventory optimization specialist for a Kenyan agricultural ERP. Analyze the following inventory data and provide structured, actionable advice. Currency is KES.

INVENTORY DATA (current state and last 60 days):
- Total stock value: KES {$totalValue}
- Items below reorder level: {$reorderCount}
- Dead stock items (no movement in 60+ days with positive SOH): {$deadCount}
- Top categories by stock value:
{$catList}
- Top fast-moving items (last 60 days):
{$fastList}

Respond ONLY with a valid JSON object (no markdown, no extra text) with this exact structure:
{
  "headline": "One-sentence inventory health assessment",
  "score": <integer 1-10>,
  "stock_optimization": {
    "insight": "What current stock distribution and dead stock indicate",
    "recommendation": "Specific stock optimization action"
  },
  "reorder_strategy": {
    "insight": "What reorder alert patterns suggest",
    "recommendation": "Specific reorder or safety stock action"
  },
  "supplier_management": {
    "insight": "What stock availability patterns suggest about supplier performance",
    "recommendation": "Specific supplier action"
  },
  "cost_reduction": {
    "insight": "Where inventory carrying costs can be reduced",
    "recommendation": "Specific cost reduction action"
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
        ], 'Inventory AI advice generated');
    }
}
