<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ItemCategorySeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('item_categories')->count() > 0) {
            return;
        }

        $now = now();
        DB::table('item_categories')->insert([
            ['name' => 'Dairy Products',       'description' => 'Milk and dairy-related items',           'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Packaging Materials',  'description' => 'Packaging, bags, and containers',        'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Animal Feeds',         'description' => 'Livestock and animal feed products',     'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Veterinary Supplies',  'description' => 'Medicines, vaccines, and vet supplies',  'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Fertilizers',          'description' => 'Chemical and organic fertilizers',       'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Farm Equipment',       'description' => 'Agricultural tools and equipment',       'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Cleaning Supplies',    'description' => 'Detergents and cleaning chemicals',      'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Office Supplies',      'description' => 'Stationery and office consumables',      'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Finished Goods',       'description' => 'Processed and packaged products for sale','inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Raw Materials',        'description' => 'Raw materials used in production',        'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Spare Parts',          'description' => 'Machinery and equipment spare parts',    'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Services',             'description' => 'Billable services and labour',           'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $this->command->info('Item categories seeded (12 categories).');
    }
}
