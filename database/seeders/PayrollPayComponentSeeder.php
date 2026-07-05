<?php

namespace Database\Seeders;

use App\Models\PayrollPayComponent;
use Illuminate\Database\Seeder;

class PayrollPayComponentSeeder extends Seeder
{
    /**
     * Seeds the four statutory deduction components PayrollGenerationService
     * looks up by computation_type — these must exist for payroll to compute
     * PAYE/SHIF/NSSF/Housing Levy. Safe to re-run (upserts by name).
     */
    public function run(): void
    {
        $statutory = [
            ['name' => 'PAYE',          'computation_type' => 'statutory_paye',          'sort_order' => 100],
            ['name' => 'SHIF',          'computation_type' => 'statutory_shif',          'sort_order' => 101],
            ['name' => 'NSSF',          'computation_type' => 'statutory_nssf',          'sort_order' => 102],
            ['name' => 'Housing Levy',  'computation_type' => 'statutory_housing_levy',  'sort_order' => 103],
        ];

        foreach ($statutory as $row) {
            PayrollPayComponent::updateOrCreate(
                ['computation_type' => $row['computation_type']],
                [
                    'name'          => $row['name'],
                    'category'      => 'deduction',
                    'is_taxable'    => false,
                    'is_statutory'  => true,
                    'active'        => true,
                    'sort_order'    => $row['sort_order'],
                ]
            );
        }

        // A couple of common, non-statutory examples — safe defaults, editable later
        PayrollPayComponent::updateOrCreate(
            ['name' => 'House Allowance', 'category' => 'allowance'],
            ['computation_type' => 'percentage_of_basic', 'percentage' => 15, 'is_taxable' => true, 'is_statutory' => false, 'active' => true, 'sort_order' => 10]
        );
        PayrollPayComponent::updateOrCreate(
            ['name' => 'Transport Allowance', 'category' => 'allowance'],
            ['computation_type' => 'fixed', 'default_amount' => 0, 'is_taxable' => true, 'is_statutory' => false, 'active' => true, 'sort_order' => 11]
        );
    }
}
