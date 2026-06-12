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

        $dairy    = $catId('Dairy Products');
        $pack     = $catId('Packaging Materials');
        $feed     = $catId('Animal Feeds');
        $vet      = $catId('Veterinary Supplies');
        $fert     = $catId('Fertilizers');
        $equip    = $catId('Farm Equipment');
        $clean    = $catId('Cleaning Supplies');
        $office   = $catId('Office Supplies');
        $spare    = $catId('Spare Parts');
        $service  = $catId('Services');

        // mb_flag: B=Bought/Purchased, M=Manufactured, S=Service
        $items = [
            // Dairy / Finished goods
            ['stock_id' => 'MILK-RAW',     'description' => 'Raw Milk',                   'category_id' => $dairy,  'mb_flag' => 'B', 'units' => 'Litres'],
            ['stock_id' => 'MILK-PAST-5',  'description' => 'Pasteurised Milk 500ml',     'category_id' => $dairy,  'mb_flag' => 'M', 'units' => 'Pieces'],
            ['stock_id' => 'MILK-PAST-1L', 'description' => 'Pasteurised Milk 1L',        'category_id' => $dairy,  'mb_flag' => 'M', 'units' => 'Pieces'],
            ['stock_id' => 'YOGHURT-500',  'description' => 'Yoghurt 500g',               'category_id' => $dairy,  'mb_flag' => 'M', 'units' => 'Pieces'],
            ['stock_id' => 'BUTTER-500',   'description' => 'Butter 500g',                'category_id' => $dairy,  'mb_flag' => 'M', 'units' => 'Pieces'],
            ['stock_id' => 'CHEESE-400',   'description' => 'Cheddar Cheese 400g',        'category_id' => $dairy,  'mb_flag' => 'M', 'units' => 'Pieces'],
            ['stock_id' => 'GHEE-1KG',     'description' => 'Ghee 1KG',                   'category_id' => $dairy,  'mb_flag' => 'M', 'units' => 'Pieces'],
            ['stock_id' => 'UHT-1L',       'description' => 'UHT Milk 1 Litre',           'category_id' => $dairy,  'mb_flag' => 'M', 'units' => 'Pieces'],
            // Packaging
            ['stock_id' => 'PACK-SACHET',  'description' => 'Milk Sachet 500ml',          'category_id' => $pack,   'mb_flag' => 'B', 'units' => 'Pieces'],
            ['stock_id' => 'PACK-BTL-1L',  'description' => 'Plastic Bottle 1L',          'category_id' => $pack,   'mb_flag' => 'B', 'units' => 'Pieces'],
            ['stock_id' => 'PACK-TETRA',   'description' => 'Tetra Pak 1L',               'category_id' => $pack,   'mb_flag' => 'B', 'units' => 'Pieces'],
            ['stock_id' => 'PACK-LABEL',   'description' => 'Product Label Roll',         'category_id' => $pack,   'mb_flag' => 'B', 'units' => 'Rolls'],
            ['stock_id' => 'PACK-CAN10L',  'description' => 'Milk Can 10L Aluminium',     'category_id' => $pack,   'mb_flag' => 'B', 'units' => 'Pieces'],
            // Animal Feeds
            ['stock_id' => 'FEED-DAIRY',   'description' => 'Dairy Meal 50KG Bag',        'category_id' => $feed,   'mb_flag' => 'B', 'units' => 'Bags'],
            ['stock_id' => 'FEED-MAIZE',   'description' => 'Maize Germ 50KG',            'category_id' => $feed,   'mb_flag' => 'B', 'units' => 'Bags'],
            ['stock_id' => 'FEED-GRASS',   'description' => 'Napier Grass Bundle',        'category_id' => $feed,   'mb_flag' => 'B', 'units' => 'Bundles'],
            ['stock_id' => 'FEED-HAY',     'description' => 'Rhodes Grass Hay Bale',      'category_id' => $feed,   'mb_flag' => 'B', 'units' => 'Bales'],
            ['stock_id' => 'FEED-MINERAL', 'description' => 'Mineral Lick Block 2KG',     'category_id' => $feed,   'mb_flag' => 'B', 'units' => 'Pieces'],
            // Veterinary
            ['stock_id' => 'VET-IVERMEC',  'description' => 'Ivermectin Injection 50ml',  'category_id' => $vet,    'mb_flag' => 'B', 'units' => 'Vials'],
            ['stock_id' => 'VET-OXY',      'description' => 'Oxytetracycline 100ml',      'category_id' => $vet,    'mb_flag' => 'B', 'units' => 'Vials'],
            ['stock_id' => 'VET-FMD',      'description' => 'FMD Vaccine 100ml',          'category_id' => $vet,    'mb_flag' => 'B', 'units' => 'Vials'],
            ['stock_id' => 'VET-TICK',     'description' => 'Acaricide Dip 5L',           'category_id' => $vet,    'mb_flag' => 'B', 'units' => 'Cans'],
            ['stock_id' => 'VET-TEAT',     'description' => 'Teat Dip 1L',               'category_id' => $vet,    'mb_flag' => 'B', 'units' => 'Bottles'],
            // Fertilizers
            ['stock_id' => 'FERT-CAN',     'description' => 'CAN Fertilizer 50KG',        'category_id' => $fert,   'mb_flag' => 'B', 'units' => 'Bags'],
            ['stock_id' => 'FERT-DAP',     'description' => 'DAP Fertilizer 50KG',        'category_id' => $fert,   'mb_flag' => 'B', 'units' => 'Bags'],
            ['stock_id' => 'FERT-NPK',     'description' => 'NPK 25:5:5 50KG',           'category_id' => $fert,   'mb_flag' => 'B', 'units' => 'Bags'],
            ['stock_id' => 'FERT-ORG',     'description' => 'Organic Compost 50KG',       'category_id' => $fert,   'mb_flag' => 'B', 'units' => 'Bags'],
            // Equipment
            ['stock_id' => 'EQUIP-MILK',   'description' => 'Milking Machine (Portable)', 'category_id' => $equip,  'mb_flag' => 'B', 'units' => 'Pieces'],
            ['stock_id' => 'EQUIP-COOL',   'description' => 'Milk Cooler 1000L',          'category_id' => $equip,  'mb_flag' => 'B', 'units' => 'Pieces'],
            ['stock_id' => 'EQUIP-CHURN',  'description' => 'Butter Churn 50L',           'category_id' => $equip,  'mb_flag' => 'B', 'units' => 'Pieces'],
            ['stock_id' => 'EQUIP-PAST',   'description' => 'Pasteuriser Unit',           'category_id' => $equip,  'mb_flag' => 'B', 'units' => 'Pieces'],
            ['stock_id' => 'EQUIP-WEIGH',  'description' => 'Weighing Scale 150KG',       'category_id' => $equip,  'mb_flag' => 'B', 'units' => 'Pieces'],
            // Cleaning
            ['stock_id' => 'CLEAN-DET',    'description' => 'Industrial Detergent 5L',    'category_id' => $clean,  'mb_flag' => 'B', 'units' => 'Cans'],
            ['stock_id' => 'CLEAN-HYPO',   'description' => 'Hypochlorite Solution 5L',   'category_id' => $clean,  'mb_flag' => 'B', 'units' => 'Cans'],
            ['stock_id' => 'CLEAN-ACID',   'description' => 'Acid Rinse Cleaner 5L',      'category_id' => $clean,  'mb_flag' => 'B', 'units' => 'Cans'],
            ['stock_id' => 'CLEAN-GLOVE',  'description' => 'Latex Gloves Box (100pcs)',  'category_id' => $clean,  'mb_flag' => 'B', 'units' => 'Boxes'],
            // Spare Parts
            ['stock_id' => 'SPARE-PUMP',   'description' => 'Pump Seal Kit',              'category_id' => $spare,  'mb_flag' => 'B', 'units' => 'Kits'],
            ['stock_id' => 'SPARE-HOSE',   'description' => 'Milking Hose 1M',            'category_id' => $spare,  'mb_flag' => 'B', 'units' => 'Metres'],
            ['stock_id' => 'SPARE-TEAT',   'description' => 'Teat Cup Liner Set',         'category_id' => $spare,  'mb_flag' => 'B', 'units' => 'Sets'],
            ['stock_id' => 'SPARE-BELT',   'description' => 'Drive Belt (Chiller)',       'category_id' => $spare,  'mb_flag' => 'B', 'units' => 'Pieces'],
            // Office
            ['stock_id' => 'OFFICE-A4',    'description' => 'A4 Paper Ream 80gsm',        'category_id' => $office, 'mb_flag' => 'B', 'units' => 'Reams'],
            ['stock_id' => 'OFFICE-PEN',   'description' => 'Ballpoint Pen Box',          'category_id' => $office, 'mb_flag' => 'B', 'units' => 'Boxes'],
            ['stock_id' => 'OFFICE-INK',   'description' => 'Printer Ink Cartridge',      'category_id' => $office, 'mb_flag' => 'B', 'units' => 'Pieces'],
            // Services
            ['stock_id' => 'SRV-TRANS',    'description' => 'Milk Collection Transport',  'category_id' => $service,'mb_flag' => 'S', 'units' => 'Trips'],
            ['stock_id' => 'SRV-LAB',      'description' => 'Lab Testing Fee',            'category_id' => $service,'mb_flag' => 'S', 'units' => 'Tests'],
            ['stock_id' => 'SRV-VET',      'description' => 'Veterinary Service Call',    'category_id' => $service,'mb_flag' => 'S', 'units' => 'Visits'],
            ['stock_id' => 'SRV-INSEM',    'description' => 'Artificial Insemination',    'category_id' => $service,'mb_flag' => 'S', 'units' => 'Sessions'],
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

        // 31 columns × 45 rows = 1395 params — within SQL Server's 2100 limit
        DB::table('items')->insert($rows);

        $this->command->info('Items seeded (' . count($rows) . ' items).');
    }
}
