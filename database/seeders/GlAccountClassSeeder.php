<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class GlAccountClassSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $classes = [
            ['id' => 1, 'name' => 'ASSETS',                  'class_type' => 'Assets'],
            ['id' => 2, 'name' => 'CURRENT ASSETS',          'class_type' => 'Assets'],
            ['id' => 3, 'name' => 'LIABILITIES',             'class_type' => 'Liabilities'],
            ['id' => 4, 'name' => 'LONG TERM LIABILITIES',   'class_type' => 'Liabilities'],
            ['id' => 5, 'name' => 'SHARE CAPITAL AND EQUITY','class_type' => 'Equity'],
            ['id' => 6, 'name' => 'INCOME',                  'class_type' => 'Income'],
            ['id' => 7, 'name' => 'EXPENSES',                'class_type' => 'Expense'],
        ];

        foreach ($classes as $class) {
            \App\Models\GlAccountClass::updateOrCreate(['id' => $class['id']], $class);
        }
    }
}
