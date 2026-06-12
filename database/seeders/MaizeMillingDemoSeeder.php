<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo data for Maize Milling manufacturing type.
 *
 * Inputs:  RAW-MAIZE    — raw maize (bought, B flag)
 * Outputs: MF-1KG       — Maize Flour 1kg  (manufactured, M)
 *          MF-2KG       — Maize Flour 2kg  (manufactured, M)
 * By-products:
 *          MF-GERM      — Maize Germ       (manufactured, M)
 *          MF-BRAN      — Maize Bran       (manufactured, M)
 *
 * BOM: 100 kg Raw Maize → 60 kg Flour (various packs) + 20 kg Germ + 12 kg Bran + 8 kg loss
 */
class MaizeMillingDemoSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Items ─────────────────────────────────────────────────────────
        $base = [
            'category_id'    => 1, 'sub_category_id' => 0, 'tax_type_id' => 3,
            'etims_item_type' => 2, 'etims_taxation_type' => 'D', 'etims_item_code' => '',
            'etims_stock_class' => '04011010', 'etims_packaging_unit' => 'KG',
            'etims_quantity_unit' => 'KG', 'etims_item_name' => '',
            'etims_stock_category' => '01', 'etims_origin_nation' => 'KE',
            'long_description' => '', 'sales_account' => '401010', 'cogs_account' => '501079',
            'inventory_account' => '103010', 'adjustment_account' => '501078',
            'wip_account' => '103035', 'dimension_id' => 0, 'dimension2_id' => 0,
            'material_cost' => 0, 'labour_cost' => 0, 'overhead_cost' => 0, 'standard_cost' => 0,
            'inactive' => 0, 'no_sale' => 0, 'no_purchase' => 0, 'editable' => 0,
            'depreciation_method' => '', 'depreciation_rate' => 0, 'depreciation_factor' => 0,
            'depreciation_start' => null, 'depreciation_date' => null,
            'fa_class_id' => '', 'bar_code' => '', 'apply_batching' => 0, 'hs_code' => '',
            'packaging_ratio' => 1, 'material_weight' => 0, 'pack_size' => 0,
            'item_commission' => 0, 'sell_in_pieces' => 0,
        ];

        $items = [
            array_merge($base, ['stock_id' => 'RAW-MAIZE', 'description' => 'Raw Maize (Bulk)',   'long_description' => 'Raw Maize (Bulk)',   'units' => 'Kgs', 'mb_flag' => 'B', 'purchase_cost' => 45.00]),
            array_merge($base, ['stock_id' => 'MF-1KG',   'description' => 'Maize Flour 1kg Pack','long_description' => 'Maize Flour 1kg Pack','units' => 'Pcs', 'mb_flag' => 'M', 'purchase_cost' => 0.00]),
            array_merge($base, ['stock_id' => 'MF-2KG',   'description' => 'Maize Flour 2kg Pack','long_description' => 'Maize Flour 2kg Pack','units' => 'Pcs', 'mb_flag' => 'M', 'purchase_cost' => 0.00]),
            array_merge($base, ['stock_id' => 'MF-GERM',  'description' => 'Maize Germ',          'long_description' => 'Maize Germ',          'units' => 'Kgs', 'mb_flag' => 'M', 'purchase_cost' => 30.00]),
            array_merge($base, ['stock_id' => 'MF-BRAN',  'description' => 'Maize Bran',          'long_description' => 'Maize Bran',          'units' => 'Kgs', 'mb_flag' => 'M', 'purchase_cost' => 20.00]),
        ];

        foreach ($items as $item) {
            DB::table('items')->updateOrInsert(
                ['stock_id' => $item['stock_id']],
                $item
            );
        }

        // ── 2. BOM for Maize Flour 1kg ────────────────────────────────────────
        // Standard batch: 100 kg raw maize → 60 pcs of 1kg flour
        $this->createBom(
            stockId:        'MF-1KG',
            description:    'Maize Flour 1kg — Milling BOM',
            version:        '1.0',
            batchQty:       60,
            batchUnit:      'Pcs',
            lines: [
                ['component_code' => 'RAW-MAIZE', 'description' => 'Raw Maize (Bulk)', 'qty_required' => 100, 'unit' => 'Kgs', 'waste_pct' => 8],
            ]
        );

        // ── 3. BOM for Maize Flour 2kg ────────────────────────────────────────
        // Standard batch: 100 kg raw maize → 30 pcs of 2kg flour
        $this->createBom(
            stockId:        'MF-2KG',
            description:    'Maize Flour 2kg — Milling BOM',
            version:        '1.0',
            batchQty:       30,
            batchUnit:      'Pcs',
            lines: [
                ['component_code' => 'RAW-MAIZE', 'description' => 'Raw Maize (Bulk)', 'qty_required' => 100, 'unit' => 'Kgs', 'waste_pct' => 8],
            ]
        );

        $this->command->info('Maize milling demo data seeded (items + BOMs). By-products: MF-GERM, MF-BRAN registered at stage completion.');
    }

    private function createBom(string $stockId, string $description, string $version, float $batchQty, string $batchUnit, array $lines): void
    {
        $maxId  = DB::table('boms')->max('id') ?? 0;
        $bomNo  = 'BOM-' . str_pad($maxId + 1, 5, '0', STR_PAD_LEFT);

        // Skip if BOM for this product + version already exists
        if (DB::table('boms')->where('product_code', $stockId)->where('version', $version)->exists()) {
            $this->command->info("  BOM for {$stockId} v{$version} already exists — skipped.");
            return;
        }

        $bomId = DB::table('boms')->insertGetId([
            'bom_no'             => $bomNo,
            'product_code'       => $stockId,
            'description'        => $description,
            'version'            => $version,
            'standard_batch_qty' => $batchQty,
            'batch_unit'         => $batchUnit,
            'is_active'          => true,
            'created_by'         => 'seeder',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        // Update BOM number with actual ID
        DB::table('boms')->where('id', $bomId)->update([
            'bom_no' => 'BOM-' . str_pad($bomId, 5, '0', STR_PAD_LEFT),
        ]);

        foreach ($lines as $i => $line) {
            DB::table('bom_items')->insert([
                'bom_id'         => $bomId,
                'component_code' => $line['component_code'],
                'description'    => $line['description'],
                'qty_required'   => $line['qty_required'],
                'unit'           => $line['unit'],
                'waste_pct'      => $line['waste_pct'] ?? 0,
                'sort_order'     => $i + 1,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        $this->command->info("  Created BOM {$bomNo} for {$stockId}");
    }
}
