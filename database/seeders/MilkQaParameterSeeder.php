<?php

namespace Database\Seeders;

use App\Models\MilkQaParameter;
use Illuminate\Database\Seeder;

class MilkQaParameterSeeder extends Seeder
{
    public function run(): void
    {
        $parameters = [
            // Compositional tests
            ['parameter_name' => 'Fat Content (%)',           'data_type' => 'number', 'display_order' => 1,  'active' => true],
            ['parameter_name' => 'Protein Content (%)',       'data_type' => 'number', 'display_order' => 2,  'active' => true],
            ['parameter_name' => 'Lactose Content (%)',       'data_type' => 'number', 'display_order' => 3,  'active' => true],
            ['parameter_name' => 'Total Solids (%)',          'data_type' => 'number', 'display_order' => 4,  'active' => true],
            ['parameter_name' => 'Solids-Not-Fat (SNF) (%)',  'data_type' => 'number', 'display_order' => 5,  'active' => true],

            // Hygiene & Freshness
            ['parameter_name' => 'Total Plate Count (TPC)',   'data_type' => 'number', 'display_order' => 6,  'active' => true],
            ['parameter_name' => 'Somatic Cell Count (SCC)',  'data_type' => 'number', 'display_order' => 7,  'active' => true],
            ['parameter_name' => 'Acidity (°SH)',             'data_type' => 'number', 'display_order' => 8,  'active' => true],
            ['parameter_name' => 'pH Level',                  'data_type' => 'number', 'display_order' => 9,  'active' => true],
            ['parameter_name' => 'Temperature at Delivery (°C)', 'data_type' => 'number', 'display_order' => 10, 'active' => true],

            // Pass/Fail tests
            ['parameter_name' => 'Alcohol Test',              'data_type' => 'test',   'display_order' => 11, 'active' => true],
            ['parameter_name' => 'Clot on Boiling (COB)',     'data_type' => 'test',   'display_order' => 12, 'active' => true],
            ['parameter_name' => 'Resazurin Test',            'data_type' => 'test',   'display_order' => 13, 'active' => true],
            ['parameter_name' => 'Antibiotic Residue Test',   'data_type' => 'test',   'display_order' => 14, 'active' => true],
            ['parameter_name' => 'Adulteration Test (Water)', 'data_type' => 'test',   'display_order' => 15, 'active' => true],

            // Text / Visual
            ['parameter_name' => 'Colour / Appearance',       'data_type' => 'text',   'display_order' => 16, 'active' => true],
            ['parameter_name' => 'Odour Assessment',          'data_type' => 'text',   'display_order' => 17, 'active' => true],
            ['parameter_name' => 'QA Inspector Remarks',      'data_type' => 'text',   'display_order' => 18, 'active' => true],
        ];

        foreach ($parameters as $p) {
            MilkQaParameter::firstOrCreate(
                ['parameter_name' => $p['parameter_name']],
                $p
            );
        }
    }
}
