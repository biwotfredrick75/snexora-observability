<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GlAccountGroupSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('gl_account_groups')->count() > 0) {
            return;
        }

        // class_id references gl_account_classes: 1=Assets,2=Liabilities,3=Equity,4=Income,5=Expenses
        $groups = [
            // Assets
            ['code' => 'CA',  'name' => 'Current Assets',          'class_id' => 1, 'parent_id' => null],
            ['code' => 'NCA', 'name' => 'Non-Current Assets',      'class_id' => 1, 'parent_id' => null],
            ['code' => 'INV', 'name' => 'Inventory',               'class_id' => 1, 'parent_id' => null],
            // Liabilities
            ['code' => 'CL',  'name' => 'Current Liabilities',     'class_id' => 2, 'parent_id' => null],
            ['code' => 'NCL', 'name' => 'Non-Current Liabilities', 'class_id' => 2, 'parent_id' => null],
            // Equity
            ['code' => 'EQ',  'name' => 'Equity',                  'class_id' => 3, 'parent_id' => null],
            ['code' => 'RE',  'name' => 'Retained Earnings',       'class_id' => 3, 'parent_id' => null],
            // Income
            ['code' => 'REV', 'name' => 'Revenue',                 'class_id' => 4, 'parent_id' => null],
            ['code' => 'OTI', 'name' => 'Other Income',            'class_id' => 4, 'parent_id' => null],
            // Expenses
            ['code' => 'COS', 'name' => 'Cost of Sales',           'class_id' => 5, 'parent_id' => null],
            ['code' => 'OPE', 'name' => 'Operating Expenses',      'class_id' => 5, 'parent_id' => null],
            ['code' => 'ADM', 'name' => 'Administration',          'class_id' => 5, 'parent_id' => null],
            ['code' => 'FIN', 'name' => 'Finance Costs',           'class_id' => 5, 'parent_id' => null],
        ];

        $now = now();
        foreach ($groups as &$g) {
            $g['inactive']   = false;
            $g['created_at'] = $now;
            $g['updated_at'] = $now;
        }

        DB::table('gl_account_groups')->insert($groups);
        $this->command->info('GL account groups seeded.');
    }
}
