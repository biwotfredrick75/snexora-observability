<?php

namespace Database\Seeders;

use App\Models\MilkBuyingPriceType;
use Illuminate\Database\Seeder;

class MilkBuyingPriceTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['name' => 'Normal',   'description' => 'Standard milk buying price for all farmers',          'active' => true],
            ['name' => 'Special',  'description' => 'Premium price for high-quality or high-volume milk',  'active' => true],
            ['name' => 'Monthly',  'description' => 'Monthly average price calculated at period end',      'active' => true],
            ['name' => 'Organic',  'description' => 'Price for certified organic milk production',         'active' => true],
            ['name' => 'New Member','description' => 'Introductory rate for newly registered farmers',     'active' => true],
            ['name' => 'Seasonal', 'description' => 'Adjusted rate applied during peak/low seasons',      'active' => false],
        ];

        foreach ($types as $type) {
            MilkBuyingPriceType::firstOrCreate(
                ['name' => $type['name']],
                $type
            );
        }
    }
}
