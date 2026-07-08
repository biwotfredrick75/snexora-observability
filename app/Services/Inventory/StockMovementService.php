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
     * Return all batches that still have remaining stock for an item at a location,
     * ordered by receipt date ascending (oldest first = FIFO).
     *
     * Each row: batch_no, remaining_qty, received_date, unit_cost
     */
    public static function availableBatches(string $stockId, string $locCode): \Illuminate\Support\Collection
    {
        return DB::table('stock_movements')
            ->where('stock_id', $stockId)
            ->where('loc_code', $locCode)
            ->whereNotNull('batch_no')
            ->where('batch_no', '!=', '')
            ->groupBy('batch_no')
            ->havingRaw('SUM(qty) > 0')
            ->selectRaw("
                batch_no,
                SUM(qty)                                              AS remaining_qty,
                MIN(tran_date)                                        AS received_date,
                AVG(CASE WHEN qty > 0 THEN price ELSE NULL END)       AS unit_cost
            ")
            ->orderByRaw('MIN(tran_date) ASC')
            ->orderBy('batch_no')
            ->get();
    }

    /**
     * Deduct stock using FIFO batch allocation, creating one movement per batch consumed.
     *
     * Required shared keys: trans_no, type, tran_date, reference, user_name
     * Optional shared keys: comments, vehicle, shift, unique_key (will be suffixed per batch)
     *
     * Older batches are consumed first.  If batched stock is exhausted, remaining qty
     * is drawn from legacy unbatched movements (batch_no = '').
     *
     * If $allowNegative is true (company setting gl_settings.allow_negative_inventory),
     * any quantity still unfulfilled after batches and unbatched stock are exhausted is
     * posted as one final movement at the location's weighted-average cost, taking the
     * balance negative — callers must not silently skip this, otherwise a sale can
     * complete with revenue/COGS posted while no stock movement records the outflow.
     *
     * Returns the unfulfilled quantity (0 when fully satisfied or backordered).
     */
    public static function deductFifo(
        string $stockId,
        string $locCode,
        float  $needed,
        array  $shared,
        bool   $allowNegative = false
    ): float {
        $boilerplate = [
            'loc_code_from' => null,
            'approved'      => 1,
            'route_id'      => '',
            'tid'           => '',
            'tidd'          => '',
            'ref_no'        => 'REF',
            'gate_pass_no'  => '',
            'batch_new'     => 0,
        ];

        $baseUniqueKey = $shared['unique_key'] ?? null;
        $tranDate      = $shared['tran_date'];

        // Batches (and legacy unbatched stock) can have no captured inbound cost —
        // e.g. an opening balance or adjustment that never recorded a price. Rather
        // than let the sale's outbound movement (and therefore its COGS) post as
        // zero, fall back to the item's standard cost — computed lazily and cached
        // per call since every zero-cost batch for this item needs the same value.
        $fallbackCost = null;
        $resolveCost  = function () use (&$fallbackCost, $stockId) {
            return $fallbackCost ??= self::standardCost($stockId);
        };

        foreach (self::availableBatches($stockId, $locCode) as $batch) {
            if ($needed <= 0) break;

            $take      = min((float) $batch->remaining_qty, $needed);
            $unitCost  = (float) ($batch->unit_cost ?? 0);
            if ($unitCost <= 0) {
                $unitCost = $resolveCost();
            }

            StockMovement::create(array_merge($boilerplate, $shared, [
                'stock_id'      => $stockId,
                'loc_code'      => $locCode,
                'qty'           => -$take,
                'price'         => $unitCost,
                'standard_cost' => $unitCost,
                'batch_no'      => $batch->batch_no,
                'date_moved'    => $tranDate,
                'unique_key'    => $baseUniqueKey ? "{$baseUniqueKey}-{$batch->batch_no}" : null,
            ]));

            $needed -= $take;
        }

        // Fallback: consume any legacy unbatched stock (no batch_no)
        if ($needed > 0) {
            $unbatched = (float) DB::table('stock_movements')
                ->where('stock_id', $stockId)
                ->where('loc_code', $locCode)
                ->where(fn($q) => $q->whereNull('batch_no')->orWhere('batch_no', ''))
                ->sum('qty');

            if ($unbatched > 0) {
                $take     = min($unbatched, $needed);
                $unitCost = $resolveCost();
                StockMovement::create(array_merge($boilerplate, $shared, [
                    'stock_id'      => $stockId,
                    'loc_code'      => $locCode,
                    'qty'           => -$take,
                    'price'         => $unitCost,
                    'standard_cost' => $unitCost,
                    'batch_no'      => '',
                    'date_moved'    => $tranDate,
                    'unique_key'    => $baseUniqueKey ? "{$baseUniqueKey}-UNBATCHED" : null,
                ]));
                $needed -= $take;
            }
        }

        // Still short after batches + unbatched stock — post the remainder at the
        // location's weighted-average cost so the balance goes negative instead of
        // the shortfall silently vanishing.
        if ($needed > 0 && $allowNegative) {
            $unitCost = self::weightedAveragePrice($stockId, $locCode);
            if ($unitCost <= 0) {
                $unitCost = $resolveCost();
            }
            StockMovement::create(array_merge($boilerplate, $shared, [
                'stock_id'      => $stockId,
                'loc_code'      => $locCode,
                'qty'           => -$needed,
                'price'         => $unitCost,
                'standard_cost' => $unitCost,
                'batch_no'      => '',
                'date_moved'    => $tranDate,
                'unique_key'    => $baseUniqueKey ? "{$baseUniqueKey}-BACKORDER" : null,
            ]));
            $needed = 0;
        }

        return max(0.0, $needed);   // 0 = fully satisfied (or backordered when allowed)
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
