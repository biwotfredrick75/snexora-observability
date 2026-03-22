<?php

namespace Database\Seeders;

use App\Models\CheckoffService;
use Illuminate\Database\Seeder;

class CheckoffServiceSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            // Loans & Credit
            ['gl_account' => '2100-001', 'service_type' => 'Loan',       'service_name' => 'Agricultural Input Loan',      'active' => true],
            ['gl_account' => '2100-002', 'service_type' => 'Loan',       'service_name' => 'Equipment Finance Loan',        'active' => true],
            ['gl_account' => '2100-003', 'service_type' => 'Loan',       'service_name' => 'Emergency Loan',                'active' => true],

            // Insurance
            ['gl_account' => '2200-001', 'service_type' => 'Insurance',  'service_name' => 'Livestock Insurance',           'active' => true],
            ['gl_account' => '2200-002', 'service_type' => 'Insurance',  'service_name' => 'Life Insurance Premium',        'active' => true],
            ['gl_account' => '2200-003', 'service_type' => 'Insurance',  'service_name' => 'NHIF Contribution',             'active' => true],

            // Savings & Contributions
            ['gl_account' => '3100-001', 'service_type' => 'Savings',    'service_name' => 'Cooperative Shares',            'active' => true],
            ['gl_account' => '3100-002', 'service_type' => 'Savings',    'service_name' => 'Development Levy',              'active' => true],

            // Input Supplies
            ['gl_account' => '4100-001', 'service_type' => 'Input',      'service_name' => 'Animal Feed Deduction',         'active' => true],
            ['gl_account' => '4100-002', 'service_type' => 'Input',      'service_name' => 'Veterinary Services',           'active' => true],
            ['gl_account' => '4100-003', 'service_type' => 'Input',      'service_name' => 'AI Services (Artificial Insemination)', 'active' => true],
            ['gl_account' => '4100-004', 'service_type' => 'Input',      'service_name' => 'Agrovet Supplies',              'active' => true],

            // Welfare & Other
            ['gl_account' => '5100-001', 'service_type' => 'Welfare',    'service_name' => 'Welfare Fund Contribution',     'active' => true],
            ['gl_account' => '5100-002', 'service_type' => 'Welfare',    'service_name' => 'Training Levy',                 'active' => false],
        ];

        foreach ($services as $s) {
            CheckoffService::firstOrCreate(
                ['service_name' => $s['service_name']],
                $s
            );
        }
    }
}
