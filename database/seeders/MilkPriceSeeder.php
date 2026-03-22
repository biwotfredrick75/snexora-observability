<?php

namespace Database\Seeders;

use App\Models\MilkPrice;
use Illuminate\Database\Seeder;

class MilkPriceSeeder extends Seeder
{
    public function run(): void
    {
        $year  = date('Y');
        $start = "{$year}-01-01";
        $end   = "{$year}-12-31";

        $prices = [
            // Normal prices — tiered by quantity
            ['price_type' => 'normal', 'from_qty' => 0.00,  'to_qty' => 10.00, 'price' => 45.0000, 'date_from' => $start, 'date_to' => $end],
            ['price_type' => 'normal', 'from_qty' => 10.01, 'to_qty' => 30.00, 'price' => 47.0000, 'date_from' => $start, 'date_to' => $end],
            ['price_type' => 'normal', 'from_qty' => 30.01, 'to_qty' => 60.00, 'price' => 49.0000, 'date_from' => $start, 'date_to' => $end],
            ['price_type' => 'normal', 'from_qty' => 60.01, 'to_qty' => 0.00,  'price' => 52.0000, 'date_from' => $start, 'date_to' => $end],

            // Special prices — premium for quality
            ['price_type' => 'special', 'from_qty' => 0.00,  'to_qty' => 20.00, 'price' => 55.0000, 'date_from' => $start, 'date_to' => $end],
            ['price_type' => 'special', 'from_qty' => 20.01, 'to_qty' => 50.00, 'price' => 58.0000, 'date_from' => $start, 'date_to' => $end],
            ['price_type' => 'special', 'from_qty' => 50.01, 'to_qty' => 0.00,  'price' => 62.0000, 'date_from' => $start, 'date_to' => $end],

            // Monthly average price
            ['price_type' => 'monthly', 'from_qty' => 0.00, 'to_qty' => 0.00, 'price' => 48.5000, 'date_from' => $start, 'date_to' => $end],
        ];

        foreach ($prices as $p) {
            // Avoid duplicates using composite key
            MilkPrice::firstOrCreate(
                [
                    'price_type' => $p['price_type'],
                    'from_qty'   => $p['from_qty'],
                    'to_qty'     => $p['to_qty'],
                    'date_from'  => $p['date_from'],
                ],
                $p
            );
        }
    }
}
