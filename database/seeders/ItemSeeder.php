<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the items table using the FA-style stock schema
 * (stock_id PK, description, mb_flag, cogs_account, etc.)
 */
class ItemSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('items')->count() > 0) {
            return;
        }

        $now = now();

        $catId = fn(string $name) => (int) DB::table('item_categories')->where('name', $name)->value('id');

        $dairy = $catId('Dairy Products');

        // mb_flag: B=Bought/Purchased, M=Manufactured, S=Service
        $items = [
            ['stock_id' => 'MILK-RAW', 'description' => 'Raw Milk', 'category_id' => $dairy, 'mb_flag' => 'B', 'units' => 'Litres'],
        ];

        $rows = [];
        foreach ($items as $item) {
            $rows[] = array_merge([
                'tax_type_id'       => 0,
                'long_description'  => null,
                'sales_account'     => '',
                'cogs_account'      => '',
                'inventory_account' => '',
                'adjustment_account'=> '',
                'wip_account'       => '',
                'dimension_id'      => null,
                'dimension2_id'     => null,
                'purchase_cost'     => 0,
                'material_cost'     => 0,
                'labour_cost'       => 0,
                'overhead_cost'     => 0,
                'inactive'          => 0,
                'no_sale'           => 0,
                'no_purchase'       => 0,
                'editable'          => 0,
                'depreciation_method' => 'S',
                'depreciation_rate'   => 0,
                'depreciation_factor' => 1,
                'depreciation_start'  => null,
                'depreciation_date'   => null,
                'fa_class_id'         => '',
                'bar_code'            => '',
                'apply_batching'      => 0,
                'created_at'          => $now,
                'updated_at'          => $now,
            ], $item);
        }

        DB::table('items')->insert($rows);

        $this->command->info('Items seeded (' . count($rows) . ' items).');
    }
}
