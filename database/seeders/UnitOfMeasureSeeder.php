<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds units_of_measure — the common units already in use across items in
 * this system (feed/dairy KG & Litres, packaging piece-counts, etc.).
 */
class UnitOfMeasureSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('units_of_measure')->count() > 0) {
            return;
        }

        $now = now();

        $units = [
            ['unit' => 'KG',       'description' => 'Kilograms',    'decimal_places' => '2'],
            ['unit' => 'TONNE',    'description' => 'Metric Tonnes','decimal_places' => '3'],
            ['unit' => 'LITRES',   'description' => 'Litres',       'decimal_places' => '2'],
            ['unit' => 'EACH',     'description' => 'Each / Pieces','decimal_places' => '0'],
            ['unit' => 'BAG',      'description' => 'Bags',         'decimal_places' => '0'],
            ['unit' => 'BOX',      'description' => 'Boxes',        'decimal_places' => '0'],
            ['unit' => 'BUNDLE',   'description' => 'Bundles',      'decimal_places' => '0'],
            ['unit' => 'BALE',     'description' => 'Bales',        'decimal_places' => '0'],
            ['unit' => 'METRE',    'description' => 'Metres',       'decimal_places' => '2'],
            ['unit' => 'SET',      'description' => 'Sets',         'decimal_places' => '0'],
            ['unit' => 'KIT',      'description' => 'Kits',         'decimal_places' => '0'],
            ['unit' => 'ROLL',     'description' => 'Rolls',        'decimal_places' => '0'],
            ['unit' => 'CAN',      'description' => 'Cans',         'decimal_places' => '0'],
            ['unit' => 'BOTTLE',   'description' => 'Bottles',      'decimal_places' => '0'],
            ['unit' => 'VIAL',     'description' => 'Vials',        'decimal_places' => '0'],
            ['unit' => 'REAM',     'description' => 'Reams',        'decimal_places' => '0'],
            ['unit' => 'DOZEN',    'description' => 'Dozen',        'decimal_places' => '0'],
            ['unit' => 'TRIP',     'description' => 'Trips',        'decimal_places' => '0'],
            ['unit' => 'TEST',     'description' => 'Tests',        'decimal_places' => '0'],
            ['unit' => 'VISIT',    'description' => 'Visits',       'decimal_places' => '0'],
            ['unit' => 'SESSION',  'description' => 'Sessions',     'decimal_places' => '0'],
        ];

        DB::table('units_of_measure')->insert(array_map(
            fn ($u) => array_merge($u, ['inactive' => false, 'created_at' => $now, 'updated_at' => $now]),
            $units
        ));

        $this->command->info('Units of measure seeded (' . count($units) . ' units).');
    }
}
