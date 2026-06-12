<?php

namespace App\Services\Manufacturing;

use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Support\Facades\DB;

/**
 * WorkOrderService
 *
 * Handles the lifecycle transitions and accounting for work orders:
 *  release()   — Draft → Released  (populate WO items from BOM, reserve stock)
 *  issueAll()  — Post goods issue for all WO lines (DR WIP / CR Inventory)
 *  complete()  — Mark WO completed, calculate final costs
 *  settle()    — Transfer WIP cost to Finished Goods (DR FG / CR WIP)
 */
class WorkOrderService
{
    // ── Status constants ──────────────────────────────────────────────────────
    const STATUS_DRAFT       = 'draft';
    const STATUS_RELEASED    = 'released';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED   = 'completed';
    const STATUS_CLOSED      = 'closed';

    // ── GL transaction type for manufacturing issues ───────────────────────
    const TYPE_WO_ISSUE   = 40;   // manufacturing goods issue
    const TYPE_WO_RECEIPT = 41;   // manufacturing goods receipt (finished goods)

    /**
     * Release a draft work order.
     * Populates WO items from the linked BOM (scaled to planned qty),
     * resolves unit costs from current inventory, and marks as Released.
     */
    public function release(WorkOrder $wo, string $user): void
    {
        if ($wo->status !== self::STATUS_DRAFT) {
            throw new \RuntimeException('Only Draft work orders can be released.');
        }
        if (! $wo->bom_id) {
            throw new \RuntimeException('Work order must have a BOM assigned before releasing.');
        }

        $bom = \App\Models\Bom::with('items')->findOrFail($wo->bom_id);

        // qty_required in BOM lines is per unit of finished output.
        // Total required = qty_required × planned_qty
        $scale = (float) $wo->planned_qty;

        // Clear any previously generated items (e.g., re-release after edit)
        $wo->items()->delete();

        $totalMaterialCost = 0;
        foreach ($bom->items as $idx => $bomLine) {
            $item = DB::table('items')->where('stock_id', $bomLine->component_code)->first();

            // Use purchase_cost; fall back to weighted-average cost from stock movements
            $unitCost = $this->resolveUnitCost($bomLine->component_code, (float) ($item?->purchase_cost ?? 0));

            // Apply waste allowance to qty required
            $qtyRequired = round($bomLine->qty_required * $scale * (1 + $bomLine->waste_pct / 100), 4);
            $lineTotal   = round($qtyRequired * $unitCost, 4);
            $totalMaterialCost += $lineTotal;

            WorkOrderItem::create([
                'work_order_id'  => $wo->id,
                'component_code' => $bomLine->component_code,
                'description'    => $bomLine->description ?: ($item?->description ?? ''),
                'qty_required'   => $qtyRequired,
                'qty_issued'     => 0,
                'unit'           => $bomLine->unit,
                'unit_cost'      => $unitCost,
                'waste_pct'      => $bomLine->waste_pct,
                'line_total'     => $lineTotal,
                'sort_order'     => $idx + 1,
            ]);
        }

        $wo->update([
            'status'              => self::STATUS_RELEASED,
            'total_material_cost' => round($totalMaterialCost, 4),
            'released_by'         => $user,
        ]);

        // Initialize process stages from the linked manufacturing type
        if ($wo->mfg_type_id) {
            $mfgType = \App\Models\ManufacturingType::find($wo->mfg_type_id);
            if ($mfgType && ! empty($mfgType->process_stages)) {
                // Clear any previously generated stages (safe to re-release)
                DB::table('work_order_stages')->where('work_order_id', $wo->id)->delete();

                $stages = collect($mfgType->process_stages)->sortBy('order');
                $first  = true;
                foreach ($stages as $stage) {
                    DB::table('work_order_stages')->insert([
                        'work_order_id'       => $wo->id,
                        'stage_order'         => (int) $stage['order'],
                        'stage_code'          => $stage['code'],
                        'stage_name'          => $stage['name'],
                        'produces_byproducts' => (bool) ($stage['produces_byproducts'] ?? false),
                        'is_final'            => (bool) ($stage['is_final'] ?? false),
                        'status'              => $first ? 'active' : 'pending',
                        'started_by'          => $first ? $user : null,
                        'started_at'          => $first ? now() : null,
                        'created_at'          => now(),
                        'updated_at'          => now(),
                    ]);
                    $first = false;
                }
            }
        }
    }

    /**
     * Post Goods Issue — stock-aware, partial-production safe.
     *
     * Logic:
     *  1. For each pending component line, read current stock on hand.
     *  2. Compute per-line ratio: available ÷ still-required.
     *  3. Bottleneck ratio = min of all ratios (capped at 1.0).
     *  4. Issue each line at: still_required × bottleneck_ratio  (never > available).
     *  5. If bottleneck = 0 (at least one component is completely out), throw — wait for supply.
     *
     * Returns array with issue summary so the controller can inform the caller.
     */
    public function issueAll(WorkOrder $wo, string $user, ?float $targetQty = null): array
    {
        if (! in_array($wo->status, [self::STATUS_RELEASED, self::STATUS_IN_PROGRESS])) {
            throw new \RuntimeException('Work order must be Released or In Progress to issue materials.');
        }

        $wo->load('items');
        $plannedQty  = (float) $wo->planned_qty;
        $targetQty   = $targetQty ?? $plannedQty;
        $scaleFactor = $plannedQty > 0 ? $targetQty / $plannedQty : 1.0;

        // ── Step 1: compute target per line and check stock ───────────────────
        $toIssueLines  = [];   // need to consume more from stock
        $toReverseLines = [];  // over-issued; need to return to stock
        $shortages     = [];

        foreach ($wo->items as $line) {
            $target  = round((float) $line->qty_required * $scaleFactor, 6);
            $current = (float) $line->qty_issued;
            $delta   = round($target - $current, 6);

            if (abs($delta) < 0.000001) continue;  // effectively zero

            if ($delta > 0) {
                $soh = max(0.0, (float) DB::table('stock_movements')
                    ->where('stock_id', $line->component_code)->sum('qty'));

                if ($soh < $delta) {
                    $shortages[] = [
                        'stock_id'  => $line->component_code,
                        'required'  => $delta,
                        'available' => $soh,
                        'shortfall' => round($delta - $soh, 4),
                    ];
                }
                $toIssueLines[] = ['line' => $line, 'delta' => $delta, 'target' => $target, 'soh' => $soh];
            } else {
                // Negative delta — return excess to stock
                $toReverseLines[] = ['line' => $line, 'delta' => $delta, 'target' => $target];
            }
        }

        if (empty($toIssueLines) && empty($toReverseLines)) {
            throw new \RuntimeException('No material adjustments needed — quantities already match the target.');
        }

        // ── Step 2: bottleneck ratio (for issue lines only) ───────────────────
        $bottleneck = 1.0;
        if (!empty($toIssueLines)) {
            $bottleneck = min(1.0, ...array_map(
                fn($p) => $p['delta'] > 0 ? min(1.0, $p['soh'] / $p['delta']) : 1.0,
                $toIssueLines
            ));
            $bottleneck = max(0.0, $bottleneck);
        }

        if ($bottleneck <= 0 && !empty($toIssueLines)) {
            $zeroItem = collect($toIssueLines)->firstWhere('soh', 0);
            throw new \RuntimeException(
                "Cannot issue: '{$zeroItem['line']->component_code}' has zero stock. "
                . 'Supply this component before proceeding.'
            );
        }

        // ── Step 3: post movements ────────────────────────────────────────────
        $netCostDelta = 0;

        DB::transaction(function () use ($toIssueLines, $toReverseLines, $bottleneck, $wo, $user, &$netCostDelta) {
            $glBase = ['trans_no' => $wo->id, 'tran_date' => now()->toDateString(), 'reference' => $wo->wo_no, 'created_by' => $user];

            // Issue lines
            foreach ($toIssueLines as $p) {
                $line    = $p['line'];
                $qty     = round($p['delta'] * $bottleneck, 6);
                $qty     = min($qty, $p['soh']);
                if ($qty <= 0) continue;

                $item     = DB::table('items')->where('stock_id', $line->component_code)->first();
                $unitCost = $this->resolveUnitCost($line->component_code, (float) ($item?->purchase_cost ?? $line->unit_cost));
                $cost     = round($qty * $unitCost, 4);
                $netCostDelta += $cost;

                DB::table('stock_movements')->insert([
                    'trans_no'      => $wo->id,
                    'stock_id'      => $line->component_code,
                    'type'          => self::TYPE_WO_ISSUE,
                    'loc_code'      => $wo->location_code,
                    'tran_date'     => now()->toDateString(),
                    'qty'           => -$qty,
                    'price'         => $unitCost,
                    'standard_cost' => $unitCost,
                    'reference'     => $wo->wo_no,
                    'comments'      => 'Goods Issue — ' . $wo->product_description,
                    'unique_key'    => \Illuminate\Support\Str::uuid()->toString(),
                ]);

                $newQtyIssued = round($line->qty_issued + $qty, 6);
                $line->update([
                    'qty_issued' => $newQtyIssued,
                    'unit_cost'  => $unitCost,
                    'line_total' => round($newQtyIssued * $unitCost, 4),
                ]);
            }

            // Reverse lines (return excess to stock)
            foreach ($toReverseLines as $p) {
                $line    = $p['line'];
                $qty     = abs($p['delta']);   // positive qty for the stock return
                $unitCost = (float) $line->unit_cost;
                $cost     = round($qty * $unitCost, 4);
                $netCostDelta -= $cost;

                DB::table('stock_movements')->insert([
                    'trans_no'      => $wo->id,
                    'stock_id'      => $line->component_code,
                    'type'          => self::TYPE_WO_ISSUE,
                    'loc_code'      => $wo->location_code,
                    'tran_date'     => now()->toDateString(),
                    'qty'           => $qty,    // positive = returning to stock
                    'price'         => $unitCost,
                    'standard_cost' => $unitCost,
                    'reference'     => $wo->wo_no,
                    'comments'      => 'Material Return — ' . $wo->product_description,
                    'unique_key'    => \Illuminate\Support\Str::uuid()->toString(),
                ]);

                $newQtyIssued = round($p['target'], 6);
                $line->update([
                    'qty_issued' => $newQtyIssued,
                    'line_total' => round($newQtyIssued * $unitCost, 4),
                ]);
            }

            // GL: DR WIP / CR Inventory (net of issues minus returns)
            if (abs($netCostDelta) > 0.0001) {
                $glSettings = DB::table('gl_settings')->first();
                $wipAccount = $glSettings?->wip_account ?? '103000';
                $invAccount = $glSettings?->items_inventory_account ?? '101010';
                DB::table('gld_transactions')->insert(array_merge($glBase, ['type' => self::TYPE_WO_ISSUE, 'account_code' => $wipAccount, 'narration' => 'WIP — ' . $wo->wo_no,         'amount' =>  $netCostDelta]));
                DB::table('gld_transactions')->insert(array_merge($glBase, ['type' => self::TYPE_WO_ISSUE, 'account_code' => $invAccount,  'narration' => 'Goods Issue — ' . $wo->wo_no, 'amount' => -$netCostDelta]));
            }

            $wo->update([
                'status'              => self::STATUS_IN_PROGRESS,
                'total_material_cost' => round($wo->items()->sum(DB::raw('qty_issued * unit_cost')), 4),
            ]);
        });

        $partial = $bottleneck < 1.0 && !empty($toIssueLines);

        return [
            'issued'          => true,
            'bottleneck_ratio'=> round($bottleneck, 4),
            'producible_qty'  => round($targetQty * $bottleneck, 4),
            'planned_qty'     => $plannedQty,
            'target_qty'      => $targetQty,
            'partial'         => $partial,
            'shortages'       => $shortages,
        ];
    }

    /**
     * Complete a work order — record actual output qty and accumulate final costs.
     */
    public function complete(WorkOrder $wo, float $actualQty, string $user): void
    {
        if ($wo->status !== self::STATUS_IN_PROGRESS) {
            throw new \RuntimeException('Only In Progress work orders can be completed.');
        }

        $totalMaterial = (float) $wo->items()->sum(DB::raw('qty_issued * unit_cost'));
        $totalLabour   = (float) $wo->labour()->sum('total_cost');
        $totalOverhead = (float) $wo->overhead()->sum('amount');
        $totalScrap    = round($totalMaterial * 0.03, 4);  // 3% scrap provision
        $totalCost     = round($totalMaterial + $totalLabour + $totalOverhead + $totalScrap, 4);
        $unitCost      = $actualQty > 0 ? round($totalCost / $actualQty, 4) : 0;

        $wo->update([
            'status'               => self::STATUS_COMPLETED,
            'actual_qty_produced'  => $actualQty,
            'completed_date'       => now()->toDateString(),
            'total_material_cost'  => round($totalMaterial, 4),
            'total_labour_cost'    => round($totalLabour, 4),
            'total_overhead_cost'  => round($totalOverhead, 4),
            'total_scrap_cost'     => $totalScrap,
            'total_cost'           => $totalCost,
            'unit_cost'            => $unitCost,
            'completed_by'         => $user,
        ]);
    }

    /**
     * Settle / close a completed work order.
     * Transfers WIP cost to Finished Goods and posts a stock receipt.
     * DR Finished Goods Inventory / CR WIP
     */
    public function settle(WorkOrder $wo, string $user): void
    {
        if ($wo->status !== self::STATUS_COMPLETED) {
            throw new \RuntimeException('Only Completed work orders can be settled.');
        }

        $item = DB::table('items')->where('stock_id', $wo->product_code)->first();
        if (! $item) {
            throw new \RuntimeException("Finished product '{$wo->product_code}' not found in items master.");
        }

        // Stock receipt for finished goods (type 41)
        DB::table('stock_movements')->insert([
            'trans_no'      => $wo->id,
            'stock_id'      => $wo->product_code,
            'type'          => self::TYPE_WO_RECEIPT,
            'loc_code'      => $wo->output_location_code ?: $wo->location_code,
            'tran_date'     => now()->toDateString(),
            'qty'           => $wo->actual_qty_produced,
            'price'         => $wo->unit_cost,
            'standard_cost' => $wo->unit_cost,
            'reference'     => $wo->wo_no,
            'comments'      => 'WO Settlement — ' . $wo->wo_no,
            'unique_key'    => \Illuminate\Support\Str::uuid()->toString(),
        ]);

        // GL: DR Finished Goods / CR WIP
        $glSettings  = DB::table('gl_settings')->first();
        $fgAccount   = $item->inventory_account ?: ($glSettings?->items_inventory_account ?? '101010');
        $wipAccount  = $glSettings?->wip_account ?? '103000';
        $totalCost   = $wo->total_cost;

        $glBase = ['trans_no' => $wo->id, 'tran_date' => now()->toDateString(), 'reference' => $wo->wo_no, 'created_by' => $user];
        DB::table('gld_transactions')->insert(array_merge($glBase, ['type' => self::TYPE_WO_RECEIPT, 'account_code' => $fgAccount,  'narration' => 'FG Receipt — ' . $wo->wo_no, 'amount' => $totalCost]));
        DB::table('gld_transactions')->insert(array_merge($glBase, ['type' => self::TYPE_WO_RECEIPT, 'account_code' => $wipAccount,  'narration' => 'WIP Clearance — ' . $wo->wo_no, 'amount' => -$totalCost]));

        $wo->update(['status' => self::STATUS_CLOSED]);
    }

    /**
     * Resolve the unit cost for a component.
     * Priority: purchase_cost (if > 0) → weighted average cost from stock movements → 0.
     */
    private function resolveUnitCost(string $stockId, float $purchaseCost): float
    {
        if ($purchaseCost > 0) {
            return $purchaseCost;
        }

        // Weighted average cost from inbound stock movements (qty > 0, price > 0)
        $wacc = DB::table('stock_movements')
            ->where('stock_id', $stockId)
            ->where('qty', '>', 0)
            ->where('price', '>', 0)
            ->selectRaw('SUM(qty * price) / NULLIF(SUM(qty), 0) as wacc')
            ->value('wacc');

        return (float) ($wacc ?? 0);
    }
}
