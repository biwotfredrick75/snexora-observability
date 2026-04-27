<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Traits\StatisticalAnalysisTrait;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchasesAnalyticsController extends Controller
{
    use StatisticalAnalysisTrait;

    // ── Endpoint: forecast ────────────────────────────────────────────────────

    public function forecast(Request $request): JsonResponse
    {
        $horizonDays = (int) $request->get('horizon', 30);
        $histDays    = (int) $request->get('history', 90);

        $histFrom = now()->subDays($histDays - 1)->toDateString();
        $histTo   = now()->toDateString();

        $rawRows = DB::table('purchase_orders')
            ->whereBetween('order_date', [$histFrom, $histTo])
            ->whereNotIn('status', ['draft', 'rejected'])
            ->selectRaw('DATE(order_date) AS date, SUM(amount_total) AS amount,
                         COUNT(*) AS po_count, COUNT(DISTINCT supplier_id) AS supplier_count')
            ->groupBy(DB::raw('DATE(order_date)'))
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
                'date'           => $d,
                'amount'         => $row ? round((float)$row->amount, 2) : 0,
                'po_count'       => $row ? (int)$row->po_count : 0,
                'supplier_count' => $row ? (int)$row->supplier_count : 0,
            ];
            $cur->addDay();
        }

        $amounts  = array_column($allDates, 'amount');
        $reg      = $this->linearRegression($amounts);
        $seasonal = $this->seasonalFactors($allDates, 'date', 'amount');
        $mae      = $this->mae($amounts, $reg['slope'], $reg['intercept']);
        $movAvg   = $this->movingAvg($amounts);

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

        $trend    = $this->classifyTrend($reg['slope'], $reg['intercept'], $n);
        $mean     = count($amounts) ? array_sum($amounts) / count($amounts) : 0;
        $variance = 0;
        foreach ($amounts as $v) { $variance += ($v - $mean) ** 2; }
        $std      = count($amounts) > 1 ? sqrt($variance / count($amounts)) : 0;
        $cv       = $mean > 0 ? round($std / $mean * 100, 1) : 0;
        $proj30d  = round(array_sum(array_column(array_slice($predictions, 0, 30), 'amount')), 2);

        // Average PO value and total unique suppliers
        $totals = DB::table('purchase_orders')
            ->whereBetween('order_date', [$histFrom, $histTo])
            ->whereNotIn('status', ['draft', 'rejected'])
            ->selectRaw('AVG(amount_total) as avg_po_value, COUNT(DISTINCT supplier_id) as supplier_count')
            ->first();

        return ApiResponse::success([
            'history'        => $allDates,
            'predictions'    => $predictions,
            'model'          => [
                'slope'          => round($reg['slope'], 4),
                'intercept'      => round($reg['intercept'], 2),
                'r2'             => $reg['r2'],
                'mae'            => round($mae, 2),
                'trend'          => $trend['label'],
                'trend_pct'      => $trend['pct'],
                'cv'             => $cv,
                'proj_30d'       => $proj30d,
            ],
            'avg_po_value'   => round((float)($totals->avg_po_value ?? 0), 2),
            'supplier_count' => (int)($totals->supplier_count ?? 0),
        ], 'Purchases forecast computed');
    }

    // ── Endpoint: insights ────────────────────────────────────────────────────

    public function insights(Request $request): JsonResponse
    {
        $to     = now()->toDateString();
        $from   = now()->subDays(59)->toDateString();
        $prev   = now()->subDays(119)->toDateString();
        $prevTo = now()->subDays(60)->toDateString();

        // Summary
        $current = DB::table('purchase_orders')
            ->whereBetween('order_date', [$from, $to])
            ->whereNotIn('status', ['draft', 'rejected'])
            ->selectRaw('SUM(amount_total) AS amount, COUNT(*) AS po_count, COUNT(DISTINCT supplier_id) AS supplier_count')
            ->first();

        $prior = DB::table('purchase_orders')
            ->whereBetween('order_date', [$prev, $prevTo])
            ->whereNotIn('status', ['draft', 'rejected'])
            ->selectRaw('SUM(amount_total) AS amount, COUNT(*) AS po_count')
            ->first();

        $curAmt  = (float)($current->amount ?? 0);
        $prAmt   = (float)($prior->amount ?? 0);
        $curPo   = (int)($current->po_count ?? 0);
        $prPo    = (int)($prior->po_count ?? 0);
        $growthAmt = $prAmt > 0 ? round(($curAmt - $prAmt) / $prAmt * 100, 1) : null;
        $growthPo  = $prPo > 0 ? round(($curPo - $prPo) / $prPo * 100, 1) : null;
        $avgPoVal  = $curPo > 0 ? round($curAmt / $curPo, 2) : 0;

        // Top 10 suppliers by spend (camelCase PK: suppliers.supplierId)
        $topSuppliers = DB::table('purchase_orders as po')
            ->join('suppliers as s', 's.supplierId', '=', 'po.supplier_id')
            ->whereBetween('po.order_date', [$from, $to])
            ->whereNotIn('po.status', ['draft', 'rejected'])
            ->selectRaw('po.supplier_id, s.supplierName as name, SUM(po.amount_total) as amount, COUNT(*) as po_count')
            ->groupBy('po.supplier_id', 's.supplierName')
            ->orderByDesc('amount')
            ->limit(10)
            ->get();

        $supplierPrior = DB::table('purchase_orders')
            ->whereBetween('order_date', [$prev, $prevTo])
            ->whereNotIn('status', ['draft', 'rejected'])
            ->selectRaw('supplier_id, SUM(amount_total) as amount_prev')
            ->groupBy('supplier_id')
            ->get()
            ->keyBy('supplier_id');

        $topSuppliers = $topSuppliers->map(function ($s) use ($supplierPrior, $curAmt) {
            $prev    = $supplierPrior->get($s->supplier_id);
            $prevAmt = $prev ? (float)$prev->amount_prev : 0;
            $growth  = $prevAmt > 0 ? round(((float)$s->amount - $prevAmt) / $prevAmt * 100, 1) : null;
            return [
                'supplier_id' => $s->supplier_id,
                'name'        => $s->name,
                'amount'      => round((float)$s->amount, 2),
                'po_count'    => (int)$s->po_count,
                'share'       => $curAmt > 0 ? round((float)$s->amount / $curAmt * 100, 1) : 0,
                'growth'      => $growth,
            ];
        });

        // Spend by item category via purchase_order_items → items → item_categories
        $byCategory = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.po_id')
            ->join('items as i', 'i.stock_id', '=', 'poi.stock_id')
            ->join('item_categories as ic', 'ic.id', '=', 'i.category_id')
            ->whereBetween('po.order_date', [$from, $to])
            ->whereNotIn('po.status', ['draft', 'rejected'])
            ->selectRaw('ic.name as category, SUM(poi.line_total) as amount, SUM(poi.qty) as qty')
            ->groupBy('ic.id', 'ic.name')
            ->orderByDesc('amount')
            ->get()
            ->map(fn($r) => [
                'category' => $r->category,
                'amount'   => round((float)$r->amount, 2),
                'qty'      => round((float)$r->qty, 2),
            ]);

        // PO approval cycle time (order_date → ceo_approval_date)
        $approvalCycle = DB::table('purchase_orders')
            ->whereBetween('order_date', [$from, $to])
            ->whereNotNull('ceo_approval_date')
            ->selectRaw('AVG(DATEDIFF(ceo_approval_date, order_date)) as avg_days,
                         MIN(DATEDIFF(ceo_approval_date, order_date)) as min_days,
                         MAX(DATEDIFF(ceo_approval_date, order_date)) as max_days,
                         COUNT(*) as po_count')
            ->first();

        // PR-to-PO conversion rate
        $prCount = DB::table('purchase_requisitions')
            ->whereBetween('requisition_date', [$from, $to])
            ->whereNotIn('status', ['draft'])
            ->count();

        $conversionRate = $prCount > 0 ? round($curPo / $prCount * 100, 1) : null;

        return ApiResponse::success([
            'period'          => ['from' => $from, 'to' => $to],
            'summary'         => [
                'amount'           => round($curAmt, 2),
                'po_count'         => $curPo,
                'supplier_count'   => (int)($current->supplier_count ?? 0),
                'avg_po_value'     => $avgPoVal,
                'growth_amount'    => $growthAmt,
                'growth_po'        => $growthPo,
                'conversion_rate'  => $conversionRate,
            ],
            'top_suppliers'   => $topSuppliers->values(),
            'by_category'     => $byCategory->values(),
            'approval_cycle'  => [
                'avg_days' => $approvalCycle ? round((float)$approvalCycle->avg_days, 1) : null,
                'min_days' => $approvalCycle ? (int)$approvalCycle->min_days : null,
                'max_days' => $approvalCycle ? (int)$approvalCycle->max_days : null,
                'po_count' => $approvalCycle ? (int)$approvalCycle->po_count : 0,
            ],
        ], 'Purchases insights computed');
    }

    // ── Endpoint: AI advice via Groq ─────────────────────────────────────────

    public function advice(Request $request): JsonResponse
    {
        $to   = now()->toDateString();
        $from = now()->subDays(59)->toDateString();
        $prev = now()->subDays(119)->toDateString();
        $prevTo = now()->subDays(60)->toDateString();

        $cur = DB::table('purchase_orders')
            ->whereBetween('order_date', [$from, $to])
            ->whereNotIn('status', ['draft', 'rejected'])
            ->selectRaw('SUM(amount_total) AS amount, COUNT(*) AS po_count, COUNT(DISTINCT supplier_id) AS suppliers')
            ->first();

        $prv = DB::table('purchase_orders')
            ->whereBetween('order_date', [$prev, $prevTo])
            ->whereNotIn('status', ['draft', 'rejected'])
            ->selectRaw('SUM(amount_total) AS amount, COUNT(*) AS po_count')
            ->first();

        $topSuppliers = DB::table('purchase_orders as po')
            ->join('suppliers as s', 's.supplierId', '=', 'po.supplier_id')
            ->whereBetween('po.order_date', [$from, $to])
            ->whereNotIn('po.status', ['draft', 'rejected'])
            ->selectRaw('s.supplierName as name, SUM(po.amount_total) as amount')
            ->groupBy('po.supplier_id', 's.supplierName')
            ->orderByDesc('amount')
            ->limit(5)
            ->get();

        $approvalCycle = DB::table('purchase_orders')
            ->whereBetween('order_date', [$from, $to])
            ->whereNotNull('ceo_approval_date')
            ->selectRaw('AVG(DATEDIFF(ceo_approval_date, order_date)) as avg_days')
            ->value('avg_days');

        $curAmt    = round((float)($cur->amount ?? 0), 2);
        $prvAmt    = round((float)($prv->amount ?? 0), 2);
        $curPo     = (int)($cur->po_count ?? 0);
        $prvPo     = (int)($prv->po_count ?? 0);
        $suppliers = (int)($cur->suppliers ?? 0);
        $avgPoVal  = $curPo > 0 ? round($curAmt / $curPo, 2) : 0;
        $growthAmt = $prvAmt > 0 ? round(($curAmt - $prvAmt) / $prvAmt * 100, 1) : 'N/A';
        $growthPo  = $prvPo > 0 ? round(($curPo - $prvPo) / $prvPo * 100, 1) : 'N/A';
        $avgDays   = $approvalCycle ? round((float)$approvalCycle, 1) : 'N/A';

        $suppList = $topSuppliers->map(fn($s) => "  - {$s->name}: KES " . number_format((float)$s->amount, 2))->implode("\n");

        $prompt = <<<PROMPT
You are a procurement and supply chain advisor for a Kenyan agricultural ERP. Analyze the following 60-day purchasing data and provide structured, actionable advice. Currency is KES.

PURCHASING DATA (last 60 days vs prior 60 days):
- Total spend: KES {$curAmt} (vs KES {$prvAmt}, growth: {$growthAmt}%)
- Purchase orders: {$curPo} (vs {$prvPo}, change: {$growthPo}%)
- Active suppliers: {$suppliers}
- Average PO value: KES {$avgPoVal}
- Average approval cycle: {$avgDays} days
- Top suppliers by spend:
{$suppList}

Respond ONLY with a valid JSON object (no markdown, no extra text) with this exact structure:
{
  "headline": "One-sentence procurement health assessment",
  "score": <integer 1-10>,
  "supplier_consolidation": {
    "insight": "What supplier concentration and spend distribution indicate",
    "recommendation": "Specific supplier rationalization action"
  },
  "cost_savings": {
    "insight": "Where procurement costs can be reduced",
    "recommendation": "Specific cost reduction or negotiation step"
  },
  "process_efficiency": {
    "insight": "What approval cycle times and PO patterns reveal",
    "recommendation": "Specific process improvement action"
  },
  "risk_management": {
    "insight": "Key supply chain risks identified",
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
        ], 'Purchases AI advice generated');
    }
}
