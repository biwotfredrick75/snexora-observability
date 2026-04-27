<?php

namespace App\Http\Controllers\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Traits\StatisticalAnalysisTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiChatController extends Controller
{
    use StatisticalAnalysisTrait;

    // Keyword → domain mapping for intent detection
    private const DOMAIN_KEYWORDS = [
        'sales'         => ['sales', 'revenue', 'invoice', 'customer', 'payment', 'debtor', 'earning', 'area', 'region', 'selling'],
        'inventory'     => ['stock', 'inventory', 'item', 'warehouse', 'reorder', 'category', 'dead stock', 'fast mover', 'soh', 'on hand'],
        'manufacturing' => ['production', 'manufacturing', 'work order', 'output', 'efficiency', 'capacity', 'product', 'produce', 'factory'],
        'purchases'     => ['purchase', 'supplier', 'procurement', 'buying', 'spend', 'vendor', 'po', 'buying cost'],
        'milk'          => ['milk', 'farmer', 'grader', 'collection', 'litre', 'liter', 'dairy', 'liters', 'dairy'],
    ];

    // Suggested prompts returned in the welcome payload
    private const SUGGESTIONS = [
        'Who are my top 10 customers by revenue?',
        'Which suppliers have the highest spend?',
        'What items are below reorder level?',
        'How efficient is our production this month?',
        'What is the milk collection trend this week?',
        'Give me a business health summary',
    ];

    public function chat(Request $request): JsonResponse
    {
        $message = trim($request->input('message', ''));
        $history = $request->input('history', []); // [{role, content}]

        if ($message === '') {
            return ApiResponse::validationError(['message' => ['The message field is required.']]);
        }

        $apiKey = config('services.groq.key');
        if (! $apiKey) {
            return ApiResponse::success([
                'error'   => 'no_key',
                'message' => 'GROQ_API_KEY is not configured in .env.',
            ], 'No API key');
        }

        // 1. Detect relevant data domains from message keywords
        $domains = $this->detectDomains($message);

        // 2. Fetch live DB data for those domains
        [$contextText, $tableData] = $this->buildContext($domains);

        // 3. Build conversation messages for Groq
        $today = now()->toDateString();
        $systemContent = "You are an AI business analyst assistant for Nexora, a Kenyan agricultural ERP. "
            . "You have access to live business data below. Answer the user's question concisely and specifically. "
            . "Use exact numbers from the data. Currency is KES. Today: {$today}. "
            . "Speak directly without referencing 'the data provided' or 'context'. "
            . "Keep answers to 3-5 sentences unless a detailed breakdown is explicitly requested. "
            . "If listing items (customers, suppliers, products), limit to the top 5-7 unless asked for more.\n\n"
            . "LIVE BUSINESS DATA:\n{$contextText}";

        $messages = [['role' => 'system', 'content' => $systemContent]];

        // Include up to 8 prior turns for context continuity
        $recentHistory = array_slice($history, -8);
        foreach ($recentHistory as $h) {
            $role = $h['role'] ?? '';
            if (in_array($role, ['user', 'assistant'], true)) {
                $messages[] = ['role' => $role, 'content' => $h['content'] ?? ''];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        // 4. Call Groq for a plain-text conversational reply
        try {
            $reply = $this->callGroqConversation($messages, 700);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }

        return ApiResponse::success([
            'reply'     => $reply,
            'table'     => $tableData,
            'generated' => now()->toISOString(),
        ], 'Chat response generated');
    }

    // ── Intent detection ──────────────────────────────────────────────────────

    private function detectDomains(string $message): array
    {
        $lower   = mb_strtolower($message);
        $matched = [];

        foreach (self::DOMAIN_KEYWORDS as $domain => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($lower, $kw)) {
                    $matched[] = $domain;
                    break;
                }
            }
        }

        // Generic/overview queries → pull all domains
        return $matched ?: array_keys(self::DOMAIN_KEYWORDS);
    }

    // ── Context builder ───────────────────────────────────────────────────────

    /** Returns [contextText, primaryTable] */
    private function buildContext(array $domains): array
    {
        $from = now()->subDays(59)->toDateString();
        $to   = now()->toDateString();

        $parts     = [];
        $tableData = null;

        foreach ($domains as $domain) {
            [$text, $table] = match ($domain) {
                'sales'         => $this->salesContext($from, $to),
                'inventory'     => $this->inventoryContext(),
                'manufacturing' => $this->manufacturingContext($from, $to),
                'purchases'     => $this->purchasesContext($from, $to),
                'milk'          => $this->milkContext($from, $to),
                default         => ['', null],
            };

            if ($text) {
                $parts[] = $text;
            }
            // Use the first non-null table as the primary report table
            $tableData ??= $table;
        }

        return [implode("\n\n", $parts), $tableData];
    }

    // ── Domain context fetchers ───────────────────────────────────────────────

    private function salesContext(string $from, string $to): array
    {
        $summary = DB::table('sales_invoices')
            ->whereBetween('invoice_date', [$from, $to])
            ->whereIn('status', ['posted', 'paid', 'partial'])
            ->selectRaw('SUM(amount_total) as revenue, COUNT(*) as invoices, COUNT(DISTINCT debtor_no) as customers')
            ->first();

        $topCustomers = DB::table('sales_invoices as i')
            ->join('customers as c', 'c.debtor_no', '=', 'i.debtor_no')
            ->whereBetween('i.invoice_date', [$from, $to])
            ->whereIn('i.status', ['posted', 'paid', 'partial'])
            ->selectRaw('c.name, SUM(i.amount_total) as revenue, COUNT(*) as invoices')
            ->groupBy('i.debtor_no', 'c.name')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get();

        $text  = "SALES (last 60 days {$from}–{$to}):\n";
        $text .= '- Total revenue: KES ' . number_format((float)($summary->revenue ?? 0), 2) . "\n";
        $text .= '- Invoices: ' . ($summary->invoices ?? 0) . ', Active customers: ' . ($summary->customers ?? 0) . "\n";
        $text .= "- Top customers:\n";
        foreach ($topCustomers as $c) {
            $text .= "  * {$c->name}: KES " . number_format((float)$c->revenue, 2) . " ({$c->invoices} invoices)\n";
        }

        $table = [
            'title'   => 'Top Customers by Revenue (Last 60 Days)',
            'columns' => ['Customer', 'Revenue (KES)', 'Invoices'],
            'rows'    => $topCustomers->map(fn($r) => [
                $r->name,
                number_format((float)$r->revenue, 2),
                $r->invoices,
            ])->values()->toArray(),
        ];

        return [$text, $table];
    }

    private function inventoryContext(): array
    {
        $byCategory = DB::table('items as i')
            ->join('item_categories as ic', 'ic.id', '=', 'i.category_id')
            ->leftJoin(DB::raw(
                '(SELECT stock_id, SUM(qty) as soh, SUM(ABS(qty) * price) as stock_value FROM stock_movements GROUP BY stock_id) as mv'
            ), 'mv.stock_id', '=', 'i.stock_id')
            ->selectRaw('ic.name as category, COUNT(i.stock_id) as items, COALESCE(SUM(mv.soh),0) as total_soh, COALESCE(SUM(mv.stock_value),0) as stock_value')
            ->groupBy('ic.id', 'ic.name')
            ->orderByDesc('stock_value')
            ->limit(10)
            ->get();

        $lowStock = DB::table('items as i')
            ->join('item_reorder_levels as rl', 'rl.stock_id', '=', 'i.stock_id')
            ->leftJoin(DB::raw('(SELECT stock_id, SUM(qty) as soh FROM stock_movements GROUP BY stock_id) as mv'), 'mv.stock_id', '=', 'i.stock_id')
            ->selectRaw('i.description, COALESCE(mv.soh,0) as soh, rl.reorder_level')
            ->whereRaw('COALESCE(mv.soh,0) < rl.reorder_level')
            ->orderByRaw('COALESCE(mv.soh,0) ASC')
            ->limit(10)
            ->get();

        $text  = "INVENTORY (current):\n";
        $text .= "- Stock by category:\n";
        foreach ($byCategory as $c) {
            $text .= "  * {$c->category}: KES " . number_format((float)$c->stock_value, 2) . " ({$c->items} items)\n";
        }
        if ($lowStock->count()) {
            $text .= "- Items below reorder level ({$lowStock->count()}):\n";
            foreach ($lowStock as $item) {
                $text .= "  * {$item->description}: SOH=" . round((float)$item->soh, 1) . ", Reorder={$item->reorder_level}\n";
            }
        }

        $table = [
            'title'   => 'Inventory Value by Category',
            'columns' => ['Category', 'Items', 'Stock Value (KES)'],
            'rows'    => $byCategory->map(fn($r) => [
                $r->category,
                $r->items,
                number_format((float)$r->stock_value, 2),
            ])->values()->toArray(),
        ];

        return [$text, $table];
    }

    private function manufacturingContext(string $from, string $to): array
    {
        $totals = DB::table('work_orders')
            ->where('status', 'completed')
            ->whereBetween('completed_date', [$from, $to])
            ->selectRaw('SUM(actual_qty_produced) as actual, SUM(planned_qty) as planned, SUM(total_cost) as cost, COUNT(*) as wo_count')
            ->first();

        $effPct = ($totals && (float)$totals->planned > 0)
            ? round((float)$totals->actual / (float)$totals->planned * 100, 1)
            : 0;

        $topProducts = DB::table('work_orders')
            ->where('status', 'completed')
            ->whereBetween('completed_date', [$from, $to])
            ->selectRaw('product_description, SUM(actual_qty_produced) as produced, COUNT(*) as wo_count')
            ->groupBy('product_code', 'product_description')
            ->orderByDesc('produced')
            ->limit(10)
            ->get();

        $text  = "MANUFACTURING (last 60 days {$from}–{$to}):\n";
        $text .= '- Work orders completed: ' . ($totals->wo_count ?? 0) . "\n";
        $text .= '- Output: ' . number_format((float)($totals->actual ?? 0), 1) . " units (planned: " . number_format((float)($totals->planned ?? 0), 1) . "), Efficiency: {$effPct}%\n";
        $text .= '- Total production cost: KES ' . number_format((float)($totals->cost ?? 0), 2) . "\n";
        $text .= "- Top products:\n";
        foreach ($topProducts as $p) {
            $text .= "  * {$p->product_description}: " . number_format((float)$p->produced, 1) . " units\n";
        }

        $table = [
            'title'   => 'Top Products by Output (Last 60 Days)',
            'columns' => ['Product', 'Units Produced', 'Work Orders'],
            'rows'    => $topProducts->map(fn($r) => [
                $r->product_description,
                number_format((float)$r->produced, 1),
                $r->wo_count,
            ])->values()->toArray(),
        ];

        return [$text, $table];
    }

    private function purchasesContext(string $from, string $to): array
    {
        $summary = DB::table('purchase_orders')
            ->whereBetween('order_date', [$from, $to])
            ->whereNotIn('status', ['draft', 'rejected'])
            ->selectRaw('SUM(amount_total) as spend, COUNT(*) as po_count, COUNT(DISTINCT supplier_id) as suppliers')
            ->first();

        $topSuppliers = DB::table('purchase_orders as po')
            ->join('suppliers as s', 's.supplierId', '=', 'po.supplier_id')
            ->whereBetween('po.order_date', [$from, $to])
            ->whereNotIn('po.status', ['draft', 'rejected'])
            ->selectRaw('s.supplierName as name, SUM(po.amount_total) as amount, COUNT(*) as po_count')
            ->groupBy('po.supplier_id', 's.supplierName')
            ->orderByDesc('amount')
            ->limit(10)
            ->get();

        $text  = "PURCHASES (last 60 days {$from}–{$to}):\n";
        $text .= '- Total spend: KES ' . number_format((float)($summary->spend ?? 0), 2) . "\n";
        $text .= '- Purchase orders: ' . ($summary->po_count ?? 0) . ', Suppliers: ' . ($summary->suppliers ?? 0) . "\n";
        $text .= "- Top suppliers:\n";
        foreach ($topSuppliers as $s) {
            $text .= "  * {$s->name}: KES " . number_format((float)$s->amount, 2) . " ({$s->po_count} POs)\n";
        }

        $table = [
            'title'   => 'Top Suppliers by Spend (Last 60 Days)',
            'columns' => ['Supplier', 'Total Spend (KES)', 'POs'],
            'rows'    => $topSuppliers->map(fn($r) => [
                $r->name,
                number_format((float)$r->amount, 2),
                $r->po_count,
            ])->values()->toArray(),
        ];

        return [$text, $table];
    }

    private function milkContext(string $from, string $to): array
    {
        $summary = DB::table('milk_grader_collections')
            ->whereBetween('date_collected', [$from, $to])
            ->selectRaw('SUM(quantity) as total_qty, COUNT(DISTINCT grader_id) as graders, COUNT(DISTINCT date_collected) as days')
            ->first();

        $totalQty = (float)($summary->total_qty ?? 0);
        $days     = max((int)($summary->days ?? 1), 1);

        $topLocations = DB::table('milk_grader_collections')
            ->whereBetween('date_collected', [$from, $to])
            ->selectRaw('location as code, SUM(quantity) as total_qty, COUNT(DISTINCT date_collected) as days, SUM(quantity*rate) as amount')
            ->groupBy('location')
            ->orderByDesc('total_qty')
            ->limit(10)
            ->get();

        $text  = "MILK COLLECTION (last 60 days {$from}–{$to}):\n";
        $text .= '- Total: ' . number_format($totalQty, 1) . " L, Graders: " . ($summary->graders ?? 0) . ", Days active: {$days}\n";
        $text .= '- Daily average: ' . number_format($totalQty / $days, 1) . " L\n";
        $text .= "- Top collection points by volume:\n";
        foreach ($topLocations as $loc) {
            $text .= "  * {$loc->code}: " . number_format((float)$loc->total_qty, 1) . " L (KES " . number_format((float)$loc->amount, 2) . ")\n";
        }

        $table = [
            'title'   => 'Top Collection Points (Last 60 Days)',
            'columns' => ['Location/Code', 'Total (L)', 'Days Active', 'Value (KES)'],
            'rows'    => $topLocations->map(fn($r) => [
                $r->code,
                number_format((float)$r->total_qty, 1),
                $r->days,
                number_format((float)$r->amount, 2),
            ])->values()->toArray(),
        ];

        return [$text, $table];
    }
}
