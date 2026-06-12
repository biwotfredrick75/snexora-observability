<?php

namespace Database\Seeders;

use App\Models\ManufacturingType;
use Illuminate\Database\Seeder;

class ManufacturingTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'code'        => 'dairy',
                'name'        => 'Dairy Processing',
                'description' => 'Milk reception, pasteurization, separation and packaging of dairy products',
                'icon'        => '🥛',
                'color'       => 'blue',
                'is_builtin'  => true,
                'sort_order'  => 1,
                'process_stages' => [
                    ['order' => 1, 'code' => 'receive',    'name' => 'Milk Reception',    'description' => 'Weigh and quality-test incoming raw milk',                 'produces_byproducts' => false, 'is_final' => false],
                    ['order' => 2, 'code' => 'filtration', 'name' => 'Filtration',        'description' => 'Filter to remove foreign matter',                         'produces_byproducts' => false, 'is_final' => false],
                    ['order' => 3, 'code' => 'pasteurize', 'name' => 'Pasteurization',    'description' => 'Heat treatment at required temp and time',                 'produces_byproducts' => false, 'is_final' => false],
                    ['order' => 4, 'code' => 'separate',   'name' => 'Cream Separation',  'description' => 'Separate cream from skim milk — cream is a by-product',   'produces_byproducts' => true,  'is_final' => false],
                    ['order' => 5, 'code' => 'process',    'name' => 'Product Processing','description' => 'Homogenise, culture, churn or blend per product',          'produces_byproducts' => false, 'is_final' => false],
                    ['order' => 6, 'code' => 'cip',        'name' => 'CIP Cleaning',      'description' => 'Clean-in-place detergent cycle',                          'produces_byproducts' => false, 'is_final' => false],
                    ['order' => 7, 'code' => 'package',    'name' => 'Packaging',         'description' => 'Fill, seal and label finished product',                   'produces_byproducts' => false, 'is_final' => false],
                    ['order' => 8, 'code' => 'qc',         'name' => 'QC Release',        'description' => 'Final quality check before dispatch — record final output','produces_byproducts' => false, 'is_final' => true],
                ],
                'qa_fields' => [
                    ['key' => 'fat_pct',        'label' => 'Fat %',             'type' => 'number', 'unit' => '%',      'required' => true],
                    ['key' => 'protein_pct',    'label' => 'Protein %',         'type' => 'number', 'unit' => '%',      'required' => false],
                    ['key' => 'bacteria_count', 'label' => 'Bacteria Count',    'type' => 'number', 'unit' => 'CFU/ml', 'required' => true],
                    ['key' => 'ph',             'label' => 'pH',                'type' => 'number', 'unit' => '',       'required' => false],
                    ['key' => 'temp_c',         'label' => 'Pasteurisation Temp','type' => 'number','unit' => '°C',     'required' => true],
                    ['key' => 'hold_time_s',    'label' => 'Hold Time (sec)',   'type' => 'number', 'unit' => 's',      'required' => false],
                    ['key' => 'acidity',        'label' => 'Titratable Acidity','type' => 'number', 'unit' => '°D',     'required' => false],
                ],
                'output_fields' => [
                    ['key' => 'cream_yield_pct',  'label' => 'Cream Yield %',   'type' => 'number', 'unit' => '%'],
                    ['key' => 'skim_pct',         'label' => 'Skim Milk %',     'type' => 'number', 'unit' => '%'],
                    ['key' => 'wastage_litres',   'label' => 'Wastage (L)',      'type' => 'number', 'unit' => 'L'],
                ],
            ],
            [
                'code'        => 'milling',
                'name'        => 'Maize Milling',
                'description' => 'Grain intake, cleaning, milling, sieving and grading of maize flour products',
                'icon'        => '🌽',
                'color'       => 'amber',
                'is_builtin'  => true,
                'sort_order'  => 2,
                'process_stages' => [
                    ['order' => 1, 'code' => 'receive',   'name' => 'Grain Intake',           'description' => 'Weigh and grade incoming maize grain',                        'produces_byproducts' => false, 'is_final' => false],
                    ['order' => 2, 'code' => 'clean',     'name' => 'Pre-Cleaning',           'description' => 'Remove debris, stones and foreign matter',                    'produces_byproducts' => false, 'is_final' => false],
                    ['order' => 3, 'code' => 'condition', 'name' => 'Tempering/Conditioning', 'description' => 'Add moisture to achieve target moisture content',             'produces_byproducts' => false, 'is_final' => false],
                    ['order' => 4, 'code' => 'dehull',    'name' => 'De-hulling',             'description' => 'Remove outer pericarp — bran is a registered by-product',    'produces_byproducts' => true,  'is_final' => false],
                    ['order' => 5, 'code' => 'mill',      'name' => 'Milling / Grinding',     'description' => 'Grind endosperm to flour particle size',                     'produces_byproducts' => false, 'is_final' => false],
                    ['order' => 6, 'code' => 'sieve',     'name' => 'Sieving / Grading',      'description' => 'Separate by particle size — germ is a registered by-product','produces_byproducts' => true,  'is_final' => false],
                    ['order' => 7, 'code' => 'enrich',    'name' => 'Fortification',          'description' => 'Add vitamins/minerals per specification',                    'produces_byproducts' => false, 'is_final' => false],
                    ['order' => 8, 'code' => 'pack',      'name' => 'Packaging',              'description' => 'Bag and seal flour to target weight — record final output',  'produces_byproducts' => false, 'is_final' => true],
                ],
                'qa_fields' => [
                    ['key' => 'moisture_pct',     'label' => 'Moisture %',        'type' => 'number', 'unit' => '%',   'required' => true],
                    ['key' => 'extraction_rate',  'label' => 'Extraction Rate %', 'type' => 'number', 'unit' => '%',   'required' => true],
                    ['key' => 'ash_pct',          'label' => 'Ash Content %',     'type' => 'number', 'unit' => '%',   'required' => false],
                    ['key' => 'protein_pct',      'label' => 'Protein %',         'type' => 'number', 'unit' => '%',   'required' => false],
                    ['key' => 'particle_size',    'label' => 'Particle Size (µm)','type' => 'number', 'unit' => 'µm',  'required' => false],
                    ['key' => 'aflatoxin_ppb',    'label' => 'Aflatoxin (ppb)',   'type' => 'number', 'unit' => 'ppb', 'required' => true],
                ],
                'output_fields' => [
                    ['key' => 'flour_grade_a_pct', 'label' => 'Grade A Flour %', 'type' => 'number', 'unit' => '%'],
                    ['key' => 'bran_pct',          'label' => 'Bran %',           'type' => 'number', 'unit' => '%'],
                    ['key' => 'germ_pct',          'label' => 'Germ %',           'type' => 'number', 'unit' => '%'],
                    ['key' => 'wastage_kg',        'label' => 'Wastage (kg)',     'type' => 'number', 'unit' => 'kg'],
                ],
            ],
            [
                'code'        => 'general',
                'name'        => 'General Manufacturing',
                'description' => 'Standard production — plan, produce, QC and pack',
                'icon'        => '⚙️',
                'color'       => 'green',
                'is_builtin'  => true,
                'sort_order'  => 3,
                'process_stages' => [
                    ['order' => 1, 'code' => 'plan',    'name' => 'Planning',     'description' => 'Schedule and allocate resources',      'produces_byproducts' => false, 'is_final' => false],
                    ['order' => 2, 'code' => 'produce', 'name' => 'Production',   'description' => 'Execute manufacturing steps',          'produces_byproducts' => false, 'is_final' => false],
                    ['order' => 3, 'code' => 'qc',      'name' => 'Quality Check','description' => 'Inspect finished product',             'produces_byproducts' => false, 'is_final' => false],
                    ['order' => 4, 'code' => 'pack',    'name' => 'Packaging',    'description' => 'Pack and label — record final output', 'produces_byproducts' => false, 'is_final' => true],
                ],
                'qa_fields'    => [],
                'output_fields'=> [],
            ],
        ];

        foreach ($types as $data) {
            ManufacturingType::updateOrCreate(['code' => $data['code']], $data);
        }
    }
}
