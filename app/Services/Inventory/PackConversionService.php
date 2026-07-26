<?php

namespace App\Services\Inventory;

use App\Models\Item;
use App\Models\ItemConversionItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * PackConversionService
 *
 * Executes the pack-size conversion ratios defined in item_conversion_items
 * (ItemConversionItem) — until now those ratios were pure master data with
 * no code anywhere that actually converted bulk stock into pack-size SKUs.
 *
 * quantity_produced on ItemConversionItem means "units of component_id
 * produced from 1 unit of stock_id" (confirmed from the existing
 * SalesItemConversion.vue screen's label/placeholder), so consuming
 * qty_to_produce units of a pack SKU requires
 * qty_to_produce / quantity_produced units of the bulk item.
 */
class PackConversionService
{
    /**
     * On-hand qty/cost for the bulk item plus every defined pack-size
     * conversion, each annotated with how many units it could max out at
     * given current stock.
     */
    public function preview(string $stockId, string $locCode): array
    {
        $bulkItem = Item::findOrFail($stockId);
        $onHandQty = StockMovementService::availableQty($stockId, $locCode);
        $unitCost  = StockMovementService::weightedAveragePrice($stockId, $locCode);

        $conversions = ItemConversionItem::where('stock_id', $stockId)
            ->with('component:stock_id,description,units,inventory_account')
            ->get()
            ->map(function (ItemConversionItem $c) use ($onHandQty) {
                $ratio = (float) $c->quantity_produced;
                return [
                    'id'               => $c->id,
                    'component_id'     => $c->component_id,
                    'description'      => $c->component?->description ?? '',
                    'units'            => $c->component?->units ?? '',
                    'quantity_produced'=> $ratio,
                    'max_producible'   => $ratio > 0 ? floor($onHandQty * $ratio * 1e6) / 1e6 : 0,
                ];
            });

        return [
            'stock_id'    => $bulkItem->stock_id,
            'description' => $bulkItem->description,
            'units'       => $bulkItem->units,
            'loc_code'    => $locCode,
            'on_hand_qty' => round($onHandQty, 6),
            'unit_cost'   => round($unitCost, 6),
            'conversions' => $conversions,
        ];
    }

    /**
     * Convert on-hand bulk stock into pack-size SKUs.
     *
     * @param  array<int, array{component_id: string, qty_to_produce: float}> $lines
     * @return array  summary of what was posted
     *
     * @throws \RuntimeException if the requested lines need more bulk stock than is on hand
     */
    public function convert(string $stockId, string $locCode, array $lines, string $user, ?string $reference = null): array
    {
        $bulkItem  = Item::findOrFail($stockId);
        $onHandQty = StockMovementService::availableQty($stockId, $locCode);
        $bulkUnitCost = StockMovementService::weightedAveragePrice($stockId, $locCode);

        $ratios = ItemConversionItem::where('stock_id', $stockId)
            ->get()
            ->keyBy('component_id');

        $resolvedLines = [];
        $totalBulkConsumed = 0.0;

        foreach ($lines as $line) {
            $componentId  = (string) $line['component_id'];
            $qtyToProduce = (float) $line['qty_to_produce'];
            if ($qtyToProduce <= 0) {
                continue;
            }

            $conversion = $ratios->get($componentId);
            if (! $conversion || (float) $conversion->quantity_produced <= 0) {
                throw new \RuntimeException("No conversion ratio defined from '{$stockId}' to '{$componentId}'.");
            }

            $bulkConsumed = $qtyToProduce / (float) $conversion->quantity_produced;
            $totalBulkConsumed += $bulkConsumed;

            $resolvedLines[] = [
                'component_id'  => $componentId,
                'qty_to_produce'=> $qtyToProduce,
                'bulk_consumed' => $bulkConsumed,
            ];
        }

        if (empty($resolvedLines)) {
            throw new \RuntimeException('No lines with a positive quantity to produce were given.');
        }

        if (round($totalBulkConsumed, 6) > round($onHandQty, 6)) {
            throw new \RuntimeException(sprintf(
                'Not enough %s on hand: this run needs %.4f %s but only %.4f %s is available at %s.',
                $stockId, $totalBulkConsumed, $bulkItem->units, $onHandQty, $bulkItem->units, $locCode
            ));
        }

        $glSettings = DB::table('gl_settings')->first();
        $fallbackAccount = $glSettings?->items_inventory_account ?: '101010';
        $bulkAccount = $bulkItem->inventory_account ?: $fallbackAccount;

        $result = DB::transaction(function () use (
            $resolvedLines, $stockId, $locCode, $bulkUnitCost, $bulkAccount,
            $fallbackAccount, $totalBulkConsumed, $user, $reference
        ) {
            // The bulk item's own outbound movement anchors trans_no for
            // every other movement/GL entry in this conversion — there's no
            // pre-existing header record (a work order, an adjustment) to
            // borrow one from here, so the movement's own auto-increment id
            // serves the same grouping purpose.
            $bulkMovement = StockMovementService::record([
                'trans_no'  => 0, // patched below once we have its own id
                'type'      => StockMovement::TYPE_PACK_CONVERSION,
                'stock_id'  => $stockId,
                'loc_code'  => $locCode,
                'qty'       => -$totalBulkConsumed,
                'price'     => $bulkUnitCost,
                'tran_date' => now()->toDateString(),
                'reference' => $reference ?: 'PACKCONV',
                'comments'  => 'Pack conversion — bulk consumed',
                'user_name' => $user,
            ]);

            // batch_no groups every movement from this one bagging run
            // together — every pack SKU produced here carries it, so a bag
            // sold in the field can be traced back to exactly which
            // conversion run (date, location, bulk consumed) produced it.
            $ref = $reference ?: ('PACKCONV-' . $bulkMovement->trans_id);
            $bulkMovement->update(['trans_no' => $bulkMovement->trans_id, 'reference' => $ref, 'batch_no' => $ref]);

            $glBase = [
                'trans_no'   => $bulkMovement->trans_id,
                'tran_date'  => now()->toDateString(),
                'reference'  => $ref,
                'created_by' => $user,
            ];

            $lineSummaries = [];
            $totalPackValue = 0.0;

            foreach ($resolvedLines as $line) {
                $packItem = Item::findOrFail($line['component_id']);
                $packAccount = $packItem->inventory_account ?: $fallbackAccount;

                // Value-preserving: cost of one pack unit = cost per bulk
                // unit × how much bulk one pack unit actually consumes.
                // No markup is introduced here — that belongs in the sales
                // price list, not this conversion.
                $packUnitCost = $line['qty_to_produce'] > 0
                    ? round(($line['bulk_consumed'] * $bulkUnitCost) / $line['qty_to_produce'], 6)
                    : 0;
                $lineValue = round($line['bulk_consumed'] * $bulkUnitCost, 4);
                $totalPackValue += $lineValue;

                StockMovementService::record([
                    'trans_no'  => $bulkMovement->trans_id,
                    'type'      => StockMovement::TYPE_PACK_CONVERSION,
                    'stock_id'  => $line['component_id'],
                    'loc_code'  => $locCode,
                    'qty'       => $line['qty_to_produce'],
                    'price'     => $packUnitCost,
                    'tran_date' => now()->toDateString(),
                    'reference' => $ref,
                    'batch_no'  => $ref,
                    'comments'  => "Pack conversion — produced from {$line['bulk_consumed']} {$packItem->units} of {$line['component_id']} equiv.",
                    'user_name' => $user,
                ]);

                // Stamp the pack SKU's own cost fields with what this run
                // actually produced it at — otherwise the item master keeps
                // whatever stale (often zero) cost it had before, which every
                // fallback elsewhere (weighted-average price, sales pricing)
                // reads first.
                DB::table('items')->where('stock_id', $line['component_id'])->update([
                    'standard_cost' => $packUnitCost,
                    'purchase_cost' => $packUnitCost,
                ]);

                // GL reclass only when the pack SKU posts to a different
                // inventory account than the bulk item — same-account
                // conversions are a net-zero reclass, nothing to post.
                if ($packAccount !== $bulkAccount && $lineValue > 0.0001) {
                    DB::table('gld_transactions')->insert(array_merge($glBase, [
                        'type'         => StockMovement::TYPE_PACK_CONVERSION,
                        'account_code' => $packAccount,
                        'narration'    => "Pack conversion — {$line['component_id']}",
                        'amount'       => $lineValue,
                    ]));
                    DB::table('gld_transactions')->insert(array_merge($glBase, [
                        'type'         => StockMovement::TYPE_PACK_CONVERSION,
                        'account_code' => $bulkAccount,
                        'narration'    => "Pack conversion — {$stockId}",
                        'amount'       => -$lineValue,
                    ]));
                }

                $lineSummaries[] = [
                    'component_id'  => $line['component_id'],
                    'qty_produced'  => round($line['qty_to_produce'], 6),
                    'bulk_consumed' => round($line['bulk_consumed'], 6),
                    'unit_cost'     => $packUnitCost,
                ];
            }

            return [
                'reference'         => $ref,
                'bulk_consumed'     => round($totalBulkConsumed, 6),
                'bulk_unit_cost'    => round($bulkUnitCost, 6),
                'total_value'       => round($totalPackValue, 4),
                'lines'             => $lineSummaries,
            ];
        });

        return $result;
    }
}
