<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryKpiController extends Controller
{
    public function index(): JsonResponse
    {
        $today = now()->toDateString();

        // ── 1. Active stock items ─────────────────────────────────────────────
        $stockItems = DB::table('items')->where('inactive', 0)->count();

        // ── 2. Total inventory value: SUM(qty × standard_cost) from movements ────
        //    standard_cost is populated at movement time and reflects actual item cost.
        $stockValue = (float) DB::table('stock_movements')
            ->selectRaw('COALESCE(SUM(qty * standard_cost), 0) as val')
            ->value('val');

        // ── 3. Low stock alerts: balance < reorder_level ──────────────────────
        //    item_reorder_levels.location_id is the integer PK of inventory_locations,
        //    stock_movements.loc_code is the string code — join through locations table.
        $lowStock = DB::table('item_reorder_levels as rl')
            ->join('inventory_locations as il', 'il.id', '=', 'rl.location_id')
            ->joinSub(
                DB::table('stock_movements')
                    ->select('stock_id', 'loc_code', DB::raw('SUM(qty) as balance'))
                    ->groupBy('stock_id', 'loc_code'),
                'bal',
                fn($j) => $j->on('bal.stock_id', '=', 'rl.stock_id')
                             ->on('bal.loc_code', '=', 'il.code')
            )
            ->whereRaw('bal.balance < rl.reorder_level')
            ->whereRaw('bal.balance > 0')
            ->count();

        // ── 4. Out-of-stock items (net qty <= 0 across all locations) ──────────
        $outOfStock = DB::table(
            DB::table('stock_movements')
                ->select('stock_id', DB::raw('SUM(qty) as net_qty'))
                ->groupBy('stock_id')
                ->havingRaw('SUM(qty) <= 0'),
            'oos'
        )->count();

        // ── 5. Today's inbound and outbound movements ─────────────────────────
        $todayIn = (float) DB::table('stock_movements')
            ->whereDate('tran_date', $today)
            ->where('qty', '>', 0)
            ->sum('qty');

        $todayOut = (float) DB::table('stock_movements')
            ->whereDate('tran_date', $today)
            ->where('qty', '<', 0)
            ->sum(DB::raw('ABS(qty)'));

        // ── 6. Pending requisitions ───────────────────────────────────────────
        $pendingRequisitions = DB::table('stock_requisitions')
            ->whereIn('status', ['pending', 'approved', 'dispatched'])
            ->count();

        // ── 7. Pending transfers ──────────────────────────────────────────────
        $pendingTransfers = DB::table('inventory_transfers')
            ->whereIn('status', ['pending', 'approved'])
            ->count();

        // ── 8. Active warehouses ──────────────────────────────────────────────
        $warehouses = DB::table('inventory_locations')->where('inactive', 0)->count();

        return ApiResponse::success([
            'stock_items'          => $stockItems,
            'stock_value'          => $stockValue,
            'low_stock_alerts'     => $lowStock,
            'out_of_stock'         => $outOfStock,
            'today_in'             => $todayIn,
            'today_out'            => $todayOut,
            'pending_requisitions' => $pendingRequisitions,
            'pending_transfers'    => $pendingTransfers,
            'warehouses'           => $warehouses,
        ], 'Inventory KPIs');
    }

    // ── Check stock availability for a list of items at a given location ──────
    public function checkAvailability(Request $request): JsonResponse
    {
        $request->validate([
            'location_id' => 'required|integer',
            'stock_ids'   => 'required|array|min:1',
            'stock_ids.*' => 'required|string',
        ]);

        $locCode = DB::table('inventory_locations')
            ->where('id', $request->location_id)
            ->value('code');

        if (! $locCode) {
            return ApiResponse::error('Location not found', 404);
        }

        // Net available at location: GRN receipts (20) minus sales deliveries (26) and transfers (13)
        $qtys = DB::table('stock_movements')
            ->whereIn('stock_id', $request->stock_ids)
            ->where('loc_code', $locCode)
            ->whereIn('type', [20, 26, 13])
            ->groupBy('stock_id')
            ->selectRaw('stock_id, SUM(qty) as qty_available')
            ->pluck('qty_available', 'stock_id');

        // Build result: every requested stock_id gets a qty (0 if not found)
        $result = collect($request->stock_ids)->mapWithKeys(fn ($id) => [
            $id => (float) ($qtys[$id] ?? 0),
        ]);

        return ApiResponse::success($result, 'Availability checked');
    }
}
