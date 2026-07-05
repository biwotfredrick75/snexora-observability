<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryReportController extends Controller
{
    // ── Stock Valuation ───────────────────────────────────────────────────────
    public function valuation(Request $request): JsonResponse
    {
        $query = DB::table('stock_movements as sm')
            ->join('items as i', 'i.stock_id', '=', 'sm.stock_id')
            ->join('inventory_locations as l', 'l.code', '=', 'sm.loc_code')
            ->leftJoin('item_categories as c', 'c.id', '=', 'i.category_id')
            ->where('i.inactive', 0)
            ->groupBy(
                'i.stock_id', 'i.description', 'i.units',
                'c.name', 'l.code', 'l.name',
                'i.purchase_cost', 'i.material_cost', 'i.labour_cost', 'i.overhead_cost'
            )
            ->havingRaw('SUM(sm.qty) > 0')
            ->selectRaw("
                i.stock_id, i.description, i.units,
                c.name as category,
                l.code as loc_code, l.name as location_name,
                ROUND(SUM(sm.qty), 4) as qty_on_hand,
                ROUND(i.purchase_cost + i.material_cost + i.labour_cost + i.overhead_cost, 4) as unit_cost,
                ROUND(SUM(sm.qty) * (i.purchase_cost + i.material_cost + i.labour_cost + i.overhead_cost), 4) as total_value
            ")
            ->orderBy('i.description');

        if ($request->filled('loc_code')) {
            $query->where('sm.loc_code', $request->loc_code);
        }
        if ($request->filled('category_id')) {
            $query->where('i.category_id', $request->category_id);
        }

        $rows = $query->get();

        return ApiResponse::success([
            'rows'        => $rows,
            'total_value' => round($rows->sum('total_value'), 4),
            'total_items' => $rows->count(),
        ], 'Stock valuation retrieved');
    }

    // ── Reorder Level ─────────────────────────────────────────────────────────
    public function reorder(Request $request): JsonResponse
    {
        $levels = DB::table('item_reorder_levels as rl')
            ->join('items as i', 'i.stock_id', '=', 'rl.stock_id')
            ->join('inventory_locations as l', 'l.id', '=', 'rl.location_id')
            ->where('i.inactive', 0)
            ->where('rl.reorder_level', '>', 0)
            ->selectRaw('i.stock_id, i.description, i.units, l.id as location_id, l.code as loc_code, l.name as location_name, rl.reorder_level')
            ->get();

        $stockQtys = DB::table('stock_movements')
            ->selectRaw('TRIM(stock_id) as stock_id, TRIM(loc_code) as loc_code, SUM(qty) as qty')
            ->groupBy('stock_id', 'loc_code')
            ->get()
            ->keyBy(fn($r) => trim($r->stock_id) . '|' . trim($r->loc_code));

        $rows = $levels->map(function ($r) use ($stockQtys) {
            $key       = $r->stock_id . '|' . $r->loc_code;
            $qty       = round((float) ($stockQtys[$key]->qty ?? 0), 4);
            $shortfall = round(max(0, $r->reorder_level - $qty), 4);
            return [
                'stock_id'      => $r->stock_id,
                'description'   => $r->description,
                'units'         => $r->units,
                'loc_code'      => $r->loc_code,
                'location_name' => $r->location_name,
                'reorder_level' => (float) $r->reorder_level,
                'qty_on_hand'   => $qty,
                'shortfall'     => $shortfall,
                'critical'      => $qty <= 0,
            ];
        })->filter(fn($r) => $r['qty_on_hand'] < $r['reorder_level'])->values();

        if ($request->filled('loc_code')) {
            $rows = $rows->filter(fn($r) => $r['loc_code'] === $request->loc_code)->values();
        }

        return ApiResponse::success([
            'rows'           => $rows,
            'total_items'    => $rows->count(),
            'critical_count' => $rows->where('critical', true)->count(),
            'total_shortfall'=> round($rows->sum('shortfall'), 4),
        ], 'Reorder level report retrieved');
    }

    // ── Stock Aging ───────────────────────────────────────────────────────────
    public function aging(Request $request): JsonResponse
    {
        $query = DB::table('stock_movements as sm')
            ->join('items as i', 'i.stock_id', '=', 'sm.stock_id')
            ->join('inventory_locations as l', 'l.code', '=', 'sm.loc_code')
            ->whereNotNull('sm.batch_no')
            ->where('sm.batch_no', '!=', '')
            ->groupBy('i.stock_id', 'i.description', 'i.units', 'sm.loc_code', 'l.name', 'sm.batch_no')
            ->havingRaw('SUM(sm.qty) > 0')
            ->selectRaw("
                i.stock_id, i.description, i.units,
                sm.loc_code, l.name as location_name,
                sm.batch_no,
                MIN(sm.tran_date) as received_date,
                ROUND(SUM(sm.qty), 4) as remaining_qty,
                DATEDIFF(CURDATE(), MIN(sm.tran_date)) as age_days,
                ROUND(AVG(CASE WHEN sm.qty > 0 THEN sm.price ELSE NULL END), 4) as unit_cost
            ")
            ->orderByRaw('age_days DESC');

        if ($request->filled('loc_code')) {
            $query->where('sm.loc_code', $request->loc_code);
        }
        if ($request->filled('min_days')) {
            $query->havingRaw('DATEDIFF(CURDATE(), MIN(sm.tran_date)) >= ?', [(int) $request->min_days]);
        }

        $rows = $query->get();

        return ApiResponse::success([
            'rows'      => $rows,
            'total'     => $rows->count(),
            'avg_age'   => $rows->count() ? round($rows->avg('age_days'), 1) : 0,
            'oldest'    => $rows->count() ? $rows->max('age_days') : 0,
        ], 'Stock aging report retrieved');
    }

    // ── Slow Moving Items ─────────────────────────────────────────────────────
    public function slowMoving(Request $request): JsonResponse
    {
        $days   = max(1, (int) $request->get('days', 90));
        $cutoff = now()->subDays($days)->toDateString();

        // Items that had outbound movement within the period
        $activeKeys = DB::table('stock_movements')
            ->where('qty', '<', 0)
            ->where('tran_date', '>=', $cutoff)
            ->selectRaw("TRIM(stock_id) + '|' + TRIM(loc_code) as k, MAX(tran_date) as last_out")
            ->groupByRaw('stock_id, loc_code')
            ->get()
            ->keyBy('k');

        $query = DB::table('stock_movements as sm')
            ->join('items as i', 'i.stock_id', '=', 'sm.stock_id')
            ->join('inventory_locations as l', 'l.code', '=', 'sm.loc_code')
            ->where('i.inactive', 0)
            ->groupBy('i.stock_id', 'i.description', 'i.units', 'sm.loc_code', 'l.name')
            ->havingRaw('SUM(sm.qty) > 0')
            ->selectRaw("
                i.stock_id, i.description, i.units,
                sm.loc_code, l.name as location_name,
                ROUND(SUM(sm.qty), 4) as qty_on_hand,
                ROUND(SUM(sm.qty) * (i.purchase_cost + i.material_cost + i.labour_cost + i.overhead_cost), 4) as stock_value
            ");

        if ($request->filled('loc_code')) {
            $query->where('sm.loc_code', $request->loc_code);
        }

        $allStock = $query->get();

        // Last outbound ever (for display)
        $lastOutAll = DB::table('stock_movements')
            ->where('qty', '<', 0)
            ->selectRaw('stock_id, loc_code, MAX(tran_date) as last_out')
            ->groupBy('stock_id', 'loc_code')
            ->get()
            ->keyBy(fn($r) => trim($r->stock_id) . '|' . trim($r->loc_code));

        $rows = $allStock->filter(function ($s) use ($activeKeys) {
            $k = $s->stock_id . '|' . $s->loc_code;
            return !isset($activeKeys[$k]);
        })->map(function ($s) use ($lastOutAll, $days) {
            $k       = $s->stock_id . '|' . $s->loc_code;
            $lastOut = $lastOutAll[$k]->last_out ?? null;
            $daysSince = $lastOut ? now()->diffInDays($lastOut) : null;
            return [
                'stock_id'      => $s->stock_id,
                'description'   => $s->description,
                'units'         => $s->units,
                'loc_code'      => $s->loc_code,
                'location_name' => $s->location_name,
                'qty_on_hand'   => (float) $s->qty_on_hand,
                'stock_value'   => (float) $s->stock_value,
                'last_out_date' => $lastOut,
                'days_inactive' => $daysSince ?? 'Never sold',
            ];
        })->values();

        return ApiResponse::success([
            'rows'        => $rows,
            'days'        => $days,
            'total_items' => $rows->count(),
            'total_value' => round($rows->sum('stock_value'), 4),
        ], 'Slow moving items retrieved');
    }

    // ── Warehouse Summary ─────────────────────────────────────────────────────
    public function warehouseSummary(Request $request): JsonResponse
    {
        $rows = DB::table('stock_movements as sm')
            ->join('inventory_locations as l', 'l.code', '=', 'sm.loc_code')
            ->join('items as i', 'i.stock_id', '=', 'sm.stock_id')
            ->where('i.inactive', 0)
            ->groupBy('l.code', 'l.name')
            ->havingRaw('SUM(sm.qty) > 0')
            ->selectRaw("
                l.code as loc_code, l.name as location_name,
                COUNT(DISTINCT sm.stock_id) as items_count,
                ROUND(SUM(sm.qty), 4) as total_units,
                ROUND(SUM(sm.qty * (i.purchase_cost + i.material_cost + i.labour_cost + i.overhead_cost)), 4) as total_value
            ")
            ->orderByRaw('total_value DESC')
            ->get();

        return ApiResponse::success([
            'rows'              => $rows,
            'total_locations'   => $rows->count(),
            'total_items'       => $rows->sum('items_count'),
            'grand_total_value' => round($rows->sum('total_value'), 4),
        ], 'Warehouse summary retrieved');
    }
}
