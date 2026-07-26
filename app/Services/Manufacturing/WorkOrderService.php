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

        // qty_required in BOM lines is the absolute amount for one full
        // standard batch (bom.standard_batch_qty — see its migration
        // comment "qty this BOM produces"; this is exactly what
        // BomController::import() stores from an ingredient sheet's
        // "amount in 1 tonne" column). Scaling to an arbitrary planned
        // output therefore means scaling relative to that standard batch,
        // not multiplying the absolute per-batch amount by the output qty
        // directly.
        $scale = (float) $bom->standard_batch_qty > 0
            ? (float) $wo->planned_qty / (float) $bom->standard_batch_qty
            : (float) $wo->planned_qty;

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
    public function issueAll(WorkOrder $wo, string $user, ?float $targetQty = null, array $lineOverrides = []): array
    {
        if (! in_array($wo->status, [self::STATUS_RELEASED, self::STATUS_IN_PROGRESS])) {
            throw new \RuntimeException('Work order must be Released or In Progress to issue materials.');
        }

        $wo->load('items');
        $plannedQty = (float) $wo->planned_qty;
        $targetQty  = $targetQty ?? $plannedQty;

        // A per-line override caps what THIS WHOLE ISSUE can achieve, not just
        // that one line — e.g. entering 1ml of starter culture when the BOM
        // needs 0.5ml/unit means "I only have enough of this for 2 units,"
        // not "issue exactly 1ml and leave every other line at the full
        // target." Computing the achievable output up front and scaling every
        // line — touched or not — to that single number is what keeps
        // qty_issued uniformly consistent across the whole BOM, so nothing is
        // ever left over to reconcile as "unused" or "spoiled" later.
        $achievableQty = $targetQty;
        foreach ($wo->items as $line) {
            if (! array_key_exists($line->id, $lineOverrides)) continue;
            $perUnitReq = $plannedQty > 0 ? (float) $line->qty_required / $plannedQty : 0;
            if ($perUnitReq <= 0) continue;
            $impliedQty    = (float) $lineOverrides[$line->id] / $perUnitReq;
            $achievableQty = min($achievableQty, $impliedQty);
        }
        $achievableQty = max(0.0, $achievableQty);
        $scaleFactor    = $plannedQty > 0 ? $achievableQty / $plannedQty : 1.0;
        $targetQty      = $achievableQty; // what's reported back as the target actually pursued

        // ── Step 1: compute target per line and check stock ───────────────────
        $toIssueLines  = [];   // need to consume more from stock
        $toReverseLines = [];  // over-issued; need to return to stock
        $shortages     = [];

        foreach ($wo->items as $line) {
            $target  = round((float) $line->qty_required * $scaleFactor, 6);
            $current = (float) $line->qty_issued;
            $delta   = round($target - $current, 6);

            // if (abs($delta) < 0.000001) continue;  // effectively zero

            if ($delta >= 0) {
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
        // Net cost delta grouped by each component's OWN inventory account —
        // a blanket account for every line (regardless of item master config)
        // would misstate inventory value for items with a non-default account.
        $invCostByAccount = [];

        DB::transaction(function () use ($toIssueLines, $toReverseLines, $bottleneck, $wo, $user, &$netCostDelta, &$invCostByAccount) {
            $glBase = ['trans_no' => $wo->id, 'tran_date' => now()->toDateString(), 'reference' => $wo->wo_no, 'created_by' => $user];
            $glSettings = DB::table('gl_settings')->first();
            $defaultInvAccount = $glSettings?->items_inventory_account ?: '101010';

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
                $invAccount = $item?->inventory_account ?: $defaultInvAccount;
                $invCostByAccount[$invAccount] = ($invCostByAccount[$invAccount] ?? 0) + $cost;

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
                $item       = DB::table('items')->where('stock_id', $line->component_code)->first();
                $invAccount = $item?->inventory_account ?: $defaultInvAccount;
                $invCostByAccount[$invAccount] = ($invCostByAccount[$invAccount] ?? 0) - $cost;

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

            // GL: DR WIP (one line) / CR Inventory (one line per distinct account)
            if (abs($netCostDelta) > 0.0001) {
                $wipAccount = $glSettings?->items_wip_account ?: '103000';
                DB::table('gld_transactions')->insert(array_merge($glBase, ['type' => self::TYPE_WO_ISSUE, 'account_code' => $wipAccount, 'narration' => 'WIP — ' . $wo->wo_no, 'amount' => $netCostDelta]));
                foreach ($invCostByAccount as $account => $amount) {
                    if (abs($amount) < 0.0001) continue;
                    DB::table('gld_transactions')->insert(array_merge($glBase, ['type' => self::TYPE_WO_ISSUE, 'account_code' => $account, 'narration' => 'Goods Issue — ' . $wo->wo_no, 'amount' => -$amount]));
                }
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

    const SHORTFALL_UNUSED  = 'unused';   // never physically consumed — return to stock
    const SHORTFALL_SPOILED = 'spoiled';  // consumed/lost in process — stays deducted, no return

    /**
     * Complete a work order — record actual output qty and accumulate final costs.
     *
     * @param string|null $shortfallReason  Required only when actual_qty is below
     *   what the issued materials would fully support. self::SHORTFALL_UNUSED
     *   returns the exact BOM-computed excess to stock (never touched it);
     *   self::SHORTFALL_SPOILED leaves qty_issued/cost as originally issued —
     *   that material is gone, not sitting back on the shelf.
     */
    public function complete(WorkOrder $wo, float $actualQty, string $user, ?string $shortfallReason = null): void
    {
        if ($wo->status !== self::STATUS_IN_PROGRESS) {
            throw new \RuntimeException('Only In Progress work orders can be completed.');
        }

        // Cap actual_qty at what the issued materials can physically support —
        // under-producing from what was issued is normal (yield loss), but
        // over-claiming output beyond issued materials creates finished-goods
        // stock with no corresponding raw-material consumption (verified this
        // was possible with zero validation: issuing 100% of BOM for a batch
        // of 10 while completing at 14 receipted 4 units nobody's cups/labels
        // ever went into; issuing only 50% while completing at the full planned
        // qty did the same and also understated unit cost).
        $lines = $wo->items()->where('qty_required', '>', 0)->get();
        if ($lines->isNotEmpty()) {
            $bottleneck = $lines->min(fn ($l) => min(1.0, (float) $l->qty_issued / (float) $l->qty_required));
            $producibleFromIssued = round((float) $wo->planned_qty * $bottleneck, 4);
            if ($actualQty > $producibleFromIssued * 1.0001) {
                throw new \RuntimeException(sprintf(
                    'Cannot complete at %.4f — issued materials only support %.4f %s (%.1f%% of BOM issued). Issue more materials first, or reduce the actual qty.',
                    $actualQty, $producibleFromIssued, $wo->unit, $bottleneck * 100
                ));
            }
        }

        // actual output needed less than what was issued? Caller must say why —
        // unused (never touched, goes back to stock) and spoiled (consumed/lost,
        // stays deducted) have opposite effects on stock and must not be guessed.
        $plannedQty      = (float) $wo->planned_qty;
        $scaleToActual   = $plannedQty > 0 ? $actualQty / $plannedQty : 1.0;
        $hasShortfall    = $wo->items->contains(fn ($l) => round((float) $l->qty_issued - (float) $l->qty_required * $scaleToActual, 6) > 0.000001);

        if ($hasShortfall && ! in_array($shortfallReason, [self::SHORTFALL_UNUSED, self::SHORTFALL_SPOILED], true)) {
            throw new \RuntimeException(
                "Materials were issued for more than {$actualQty} {$wo->unit} needs. "
                . "Specify whether the difference is '" . self::SHORTFALL_UNUSED . "' (never touched — return it to stock) "
                . "or '" . self::SHORTFALL_SPOILED . "' (consumed/lost in the process — write it off, no return)."
            );
        }

        DB::transaction(function () use ($wo, $actualQty, $scaleToActual, $shortfallReason, $user) {
            $wo->load('items');
            foreach ($wo->items as $line) {
                // Exactly what actual_qty needed per the BOM ratio already baked
                // into qty_required — never approximated, never re-derived.
                $neededForActual = round((float) $line->qty_required * $scaleToActual, 6);
                $excess          = round((float) $line->qty_issued - $neededForActual, 6);
                if ($excess <= 0.000001) continue;

                if ($shortfallReason === self::SHORTFALL_SPOILED) {
                    // Spoiled — material is gone. Leave qty_issued/cost exactly as
                    // issued; do not touch stock at all.
                    continue;
                }

                $unitCost = (float) $line->unit_cost;
                $cost     = round($excess * $unitCost, 4);

                DB::table('stock_movements')->insert([
                    'trans_no'      => $wo->id,
                    'stock_id'      => $line->component_code,
                    'type'          => self::TYPE_WO_ISSUE,
                    'loc_code'      => $wo->location_code,
                    'tran_date'     => now()->toDateString(),
                    'qty'           => $excess, // positive = returning unused material to stock
                    'price'         => $unitCost,
                    'standard_cost' => $unitCost,
                    'reference'     => $wo->wo_no,
                    'comments'      => 'Material Return (unused — actual output below issued qty) — ' . $wo->product_description,
                    'unique_key'    => \Illuminate\Support\Str::uuid()->toString(),
                ]);

                $line->update([
                    'qty_issued' => $neededForActual,
                    'line_total' => round($neededForActual * $unitCost, 4),
                ]);

                if ($cost > 0.0001) {
                    $glSettings = DB::table('gl_settings')->first();
                    $wipAccount = $glSettings?->items_wip_account ?: '103000';
                    $itemMaster = DB::table('items')->where('stock_id', $line->component_code)->first();
                    $invAccount = $itemMaster?->inventory_account ?: ($glSettings?->items_inventory_account ?: '101010');
                    $glBase     = ['trans_no' => $wo->id, 'tran_date' => now()->toDateString(), 'reference' => $wo->wo_no, 'created_by' => $user];

                    DB::table('gld_transactions')->insert(array_merge($glBase, ['type' => self::TYPE_WO_ISSUE, 'account_code' => $invAccount, 'narration' => 'Material Return — ' . $wo->wo_no, 'amount' => $cost]));
                    DB::table('gld_transactions')->insert(array_merge($glBase, ['type' => self::TYPE_WO_ISSUE, 'account_code' => $wipAccount, 'narration' => 'WIP — ' . $wo->wo_no, 'amount' => -$cost]));
                }
            }

            $totalMaterial = (float) $wo->items()->sum(DB::raw('qty_issued * unit_cost'));
            $totalLabour   = (float) $wo->labour()->sum('total_cost');
            $totalOverhead = (float) $wo->overhead()->sum('amount');
            // Scrap provision — configurable per BOM (Bom::scrap_pct). Not set
            // (or no BOM at all, e.g. a standalone WO) means 0 — no provision —
            // not the old flat 3% default.
            $scrapPct      = $wo->bom?->scrap_pct ?? 0.0;
            $totalScrap    = round($totalMaterial * ($scrapPct / 100), 4);
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

            // A WO created from a Production Plan item (via "Create WO") never fed
            // its output back to the plan — the Plan Items Progress table stayed at
            // 0%/"Create WO" forever even after the linked WO closed, inviting a
            // duplicate WO for an already-fulfilled item. Sync it here, where
            // actual output is recorded, and auto-complete the plan once every
            // item is fully produced (mirrors ProductionPlanController::execute()).
            if ($wo->production_plan_item_id) {
                $planItem = \App\Models\ProductionPlanItem::find($wo->production_plan_item_id);
                if ($planItem) {
                    $planItem->increment('actual_qty', $actualQty);

                    $plan = \App\Models\ProductionPlan::with('items')->find($planItem->production_plan_id);
                    if ($plan && $plan->items->every(fn ($i) => $i->actual_qty >= $i->planned_qty)) {
                        $plan->update(['status' => 'completed']);
                    }
                }
            }
        });
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

        // Only material + labour + overhead were ever actually debited into WIP
        // (via issueAll()/addLabour()/addOverhead()) — total_cost also bakes in
        // a 3% scrap provision with no originating transaction. Crediting WIP
        // for total_cost would credit more than was ever debited, leaving WIP
        // permanently short by the scrap amount. Settle for the real posted
        // amount; total_cost/unit_cost stay on the WO record as-is for the
        // cost sheet's pricing guidance, unaffected.
        $postedCost = round(
            (float) $wo->total_material_cost + (float) $wo->total_labour_cost + (float) $wo->total_overhead_cost,
            4
        );
        $postedUnitCost = $wo->actual_qty_produced > 0 ? round($postedCost / $wo->actual_qty_produced, 4) : 0;

        // Stock receipt for finished goods (type 41). batch_no = the WO
        // number itself — every unit from this production run carries it,
        // so a bag sold in the field traces straight back to which Work
        // Order (and therefore which BOM/ingredients/date) produced it.
        DB::table('stock_movements')->insert([
            'trans_no'      => $wo->id,
            'stock_id'      => $wo->product_code,
            'type'          => self::TYPE_WO_RECEIPT,
            'loc_code'      => $wo->output_location_code ?: $wo->location_code,
            'tran_date'     => now()->toDateString(),
            'qty'           => $wo->actual_qty_produced,
            'price'         => $postedUnitCost,
            'standard_cost' => $postedUnitCost,
            'reference'     => $wo->wo_no,
            'batch_no'      => $wo->wo_no,
            'comments'      => 'WO Settlement — ' . $wo->wo_no,
            'unique_key'    => \Illuminate\Support\Str::uuid()->toString(),
        ]);

        // GL: DR Finished Goods / CR WIP
        $glSettings  = DB::table('gl_settings')->first();
        $fgAccount   = $item->inventory_account ?: ($glSettings?->items_inventory_account ?: '101010');
        $wipAccount  = $glSettings?->items_wip_account ?: '103000';

        $glBase = ['trans_no' => $wo->id, 'tran_date' => now()->toDateString(), 'reference' => $wo->wo_no, 'created_by' => $user];
        DB::table('gld_transactions')->insert(array_merge($glBase, ['type' => self::TYPE_WO_RECEIPT, 'account_code' => $fgAccount,  'narration' => 'FG Receipt — ' . $wo->wo_no, 'amount' => $postedCost]));
        DB::table('gld_transactions')->insert(array_merge($glBase, ['type' => self::TYPE_WO_RECEIPT, 'account_code' => $wipAccount,  'narration' => 'WIP Clearance — ' . $wo->wo_no, 'amount' => -$postedCost]));

        // Stamp the finished product's own cost fields with what production
        // actually cost — otherwise the item master keeps whatever stale (often
        // zero) purchase_cost it had before, which every cost fallback
        // elsewhere (BOM costing, weighted-average price, etc.) reads first.
        // Uses wo->unit_cost (materials+labour+overhead+scrap ÷ actual qty —
        // the same figure the Cost Sheet report shows), not the scrap-excluded
        // $postedUnitCost above, which exists only to keep the GL entry from
        // crediting WIP for a scrap provision that was never actually debited.
        DB::table('items')->where('stock_id', $wo->product_code)->update([
            'standard_cost' => $wo->unit_cost,
            'purchase_cost' => $wo->unit_cost,
        ]);

        $wo->update(['status' => self::STATUS_CLOSED]);
    }

    /**
     * Posts a labour or overhead cost entry into WIP: DR WIP / CR the account
     * the caller nominates it was paid from/accrued to (e.g. a wages clearing
     * account, an accrued-overhead account, or cash). Without this, labour and
     * overhead were only ever cached as totals on the work order and never
     * actually entered the ledger, yet settle() used to credit WIP for them
     * anyway — a debit that never happened being cleared out from under it.
     */
    public function postCostEntry(WorkOrder $wo, float $amount, string $creditAccount, string $narration, string $user): void
    {
        $glSettings = DB::table('gl_settings')->first();
        $wipAccount = $glSettings?->items_wip_account ?: '103000';
        $glBase = ['trans_no' => $wo->id, 'tran_date' => now()->toDateString(), 'reference' => $wo->wo_no, 'created_by' => $user];

        DB::table('gld_transactions')->insert(array_merge($glBase, ['type' => self::TYPE_WO_ISSUE, 'account_code' => $wipAccount,     'narration' => $narration, 'amount' => $amount]));
        DB::table('gld_transactions')->insert(array_merge($glBase, ['type' => self::TYPE_WO_ISSUE, 'account_code' => $creditAccount, 'narration' => $narration, 'amount' => -$amount]));
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
