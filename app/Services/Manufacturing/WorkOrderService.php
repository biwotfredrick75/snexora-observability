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
    public function issueAll(WorkOrder $wo, string $user): array
    {
        if (! in_array($wo->status, [self::STATUS_RELEASED, self::STATUS_IN_PROGRESS])) {
            throw new \RuntimeException('Work order must be Released or In Progress to issue materials.');
        }

        $wo->load('items');

        // ── Step 1: gather pending lines + stock on hand ──────────────────────
        $pending = [];
        foreach ($wo->items as $line) {
            $remaining = round($line->qty_required - $line->qty_issued, 6);
            if ($remaining <= 0) continue;

            $soh = (float) DB::table('stock_movements')
                ->where('stock_id', $line->component_code)
                ->sum('qty');                          // sum across all locations
            $soh = max(0.0, $soh);                    // floor at zero — no phantom stock

            $pending[] = [
                'line'      => $line,
                'remaining' => $remaining,
                'soh'       => $soh,
                'ratio'     => $remaining > 0 ? $soh / $remaining : 1.0,
            ];
        }

        if (empty($pending)) {
            throw new \RuntimeException('All material lines are already fully issued.');
        }

        // ── Step 2: bottleneck ratio ──────────────────────────────────────────
        $bottleneck = min(1.0, ...array_column($pending, 'ratio'));
        $bottleneck = max(0.0, $bottleneck);

        // Build shortage report for response
        $shortages = array_values(array_filter($pending, fn($p) => $p['soh'] < $p['remaining']));
        $shortages = array_map(fn($p) => [
            'stock_id'  => $p['line']->component_code,
            'required'  => $p['remaining'],
            'available' => $p['soh'],
            'shortfall' => round($p['remaining'] - $p['soh'], 4),
        ], $shortages);

        if ($bottleneck <= 0) {
            // At least one component is completely out — cannot produce anything
            $zeroItem = collect($pending)->firstWhere('soh', 0);
            throw new \RuntimeException(
                "Cannot issue: '{$zeroItem['line']->component_code}' ({$zeroItem['line']->description}) "
                . "has zero stock. Supply this component before proceeding."
            );
        }

        // ── Step 3: issue each line at bottleneck-scaled quantity ─────────────
        $totalIssued = 0;

        DB::transaction(function () use ($pending, $bottleneck, $wo, $user, &$totalIssued) {
            foreach ($pending as $p) {
                $line      = $p['line'];
                $toIssue   = round($p['remaining'] * $bottleneck, 6);
                $toIssue   = min($toIssue, $p['soh']);   // hard cap — never exceed available

                if ($toIssue <= 0) continue;

                $item     = DB::table('items')->where('stock_id', $line->component_code)->first();
                $unitCost = $this->resolveUnitCost($line->component_code, (float) ($item?->purchase_cost ?? $line->unit_cost));
                $lineTotal = round($toIssue * $unitCost, 4);
                $totalIssued += $lineTotal;

                DB::table('stock_movements')->insert([
                    'trans_no'      => $wo->id,
                    'stock_id'      => $line->component_code,
                    'type'          => self::TYPE_WO_ISSUE,
                    'loc_code'      => $wo->location_code,
                    'tran_date'     => now()->toDateString(),
                    'qty'           => -$toIssue,
                    'price'         => $unitCost,
                    'standard_cost' => $unitCost,
                    'reference'     => $wo->wo_no,
                    'comments'      => 'Goods Issue — ' . $wo->product_description,
                    'unique_key'    => \Illuminate\Support\Str::uuid()->toString(),
                ]);

                $line->update([
                    'qty_issued' => round($line->qty_issued + $toIssue, 6),
                    'unit_cost'  => $unitCost,
                    'line_total' => round(($line->qty_issued + $toIssue) * $unitCost, 4),
                ]);
            }

            // GL: DR WIP / CR Inventory
            if ($totalIssued > 0) {
                $glSettings = DB::table('gl_settings')->first();
                $wipAccount = $glSettings?->wip_account ?? '103000';
                $invAccount = $glSettings?->items_inventory_account ?? '101010';
                $glBase     = ['trans_no' => $wo->id, 'tran_date' => now()->toDateString(), 'reference' => $wo->wo_no, 'created_by' => $user];
                DB::table('gld_transactions')->insert(array_merge($glBase, ['type' => self::TYPE_WO_ISSUE, 'account_code' => $wipAccount, 'narration' => 'WIP — ' . $wo->wo_no,         'amount' =>  $totalIssued]));
                DB::table('gld_transactions')->insert(array_merge($glBase, ['type' => self::TYPE_WO_ISSUE, 'account_code' => $invAccount,  'narration' => 'Goods Issue — ' . $wo->wo_no, 'amount' => -$totalIssued]));
            }

            $wo->update([
                'status'              => self::STATUS_IN_PROGRESS,
                'total_material_cost' => round($wo->items()->sum(DB::raw('qty_issued * unit_cost')), 4),
            ]);
        });

        $producibleQty = round($wo->planned_qty * $bottleneck, 4);

        return [
            'issued'          => true,
            'bottleneck_ratio'=> round($bottleneck, 4),
            'producible_qty'  => $producibleQty,
            'planned_qty'     => $wo->planned_qty,
            'partial'         => $bottleneck < 1.0,
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
