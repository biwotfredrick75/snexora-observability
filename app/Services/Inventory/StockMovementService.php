<?php

namespace App\Services\Inventory;

use App\Models\Item;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

class StockMovementService
{
    /**
     * Return the current stock balance for an item at a location.
     * Pass lockForUpdate = true inside an open transaction to prevent races.
     */
    public static function availableQty(string $stockId, string $locCode, bool $lockForUpdate = false): float
    {
        $query = StockMovement::where('stock_id', $stockId)
            ->where('loc_code', $locCode);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return (float) $query->sum('qty');
    }

    /**
     * Weighted average price for an item at a given location.
     * Formula: SUM(qty * price) / SUM(qty) across all movements at that location.
     * Falls back to the item's purchase_cost when no movements exist yet.
     */
    public static function weightedAveragePrice(string $stockId, string $locCode): float
    {
        $row = DB::table('stock_movements')
            ->where('stock_id', $stockId)
            ->where('loc_code', $locCode)
            ->selectRaw('SUM(qty) as total_qty, SUM(qty * price) as total_value')
            ->first();

        if ($row && (float) $row->total_qty != 0) {
            return round((float) $row->total_value / (float) $row->total_qty, 6);
        }

        // Fallback: purchase cost from item master
        return (float) Item::where('stock_id', $stockId)->value('purchase_cost') ?? 0.0;
    }

    /**
     * Standard cost for an item = material_cost + labour_cost + overhead_cost.
     * Falls back to purchase_cost if all cost components are zero.
     */
    public static function standardCost(string $stockId): float
    {
        $item = Item::where('stock_id', $stockId)
            ->select('material_cost', 'labour_cost', 'overhead_cost', 'purchase_cost')
            ->first();

        if (!$item) {
            return 0.0;
        }

        $std = (float) $item->material_cost
             + (float) $item->labour_cost
             + (float) $item->overhead_cost;

        return $std > 0 ? $std : (float) $item->purchase_cost;
    }

    /**
     * Write one stock movement row.
     *
     * Required keys : trans_no, type, stock_id, loc_code, qty, tran_date, reference, user_name
     * Optional keys : loc_code_from, price, standard_cost, comments, vehicle, shift, unique_key
     *
     * If price or standard_cost are omitted they are resolved automatically:
     *   price         → weighted average price at loc_code
     *   standard_cost → material + labour + overhead cost from item master
     */
    public static function record(array $data): StockMovement
    {
        $stockId = $data['stock_id'];
        $locCode = $data['loc_code'];

        $price        = $data['price']         ?? self::weightedAveragePrice($stockId, $locCode);
        $standardCost = $data['standard_cost'] ?? self::standardCost($stockId);

        return StockMovement::create(array_merge([
            'loc_code_from' => null,
            'comments'      => '',
            'vehicle'       => '',
            'shift'         => '',
            'unique_key'    => null,
            // fixed boilerplate
            'approved'      => 1,
            'route_id'      => '',
            'tid'           => '',
            'tidd'          => '',
            'ref_no'        => 'REF',
            'gate_pass_no'  => '',
            'batch_no'      => '',
        ], $data, [
            'price'         => $price,
            'standard_cost' => $standardCost,
            'date_moved'    => $data['tran_date'] ?? $data['date_moved'] ?? null,
        ]));
    }
}
