<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['code' => 'annual',       'name' => 'Annual Leave',       'default_days_per_year' => 21, 'is_paid' => true],
            ['code' => 'sick',         'name' => 'Sick Leave',         'default_days_per_year' => 14, 'is_paid' => true],
            ['code' => 'compassionate','name' => 'Compassionate Leave','default_days_per_year' => 5,  'is_paid' => true],
            ['code' => 'maternity',    'name' => 'Maternity Leave',    'default_days_per_year' => 90, 'is_paid' => true],
            ['code' => 'paternity',    'name' => 'Paternity Leave',    'default_days_per_year' => 14, 'is_paid' => true],
            ['code' => 'unpaid',       'name' => 'Unpaid Leave',       'default_days_per_year' => 0,  'is_paid' => false],
        ];

        foreach ($types as $type) {
            LeaveType::firstOrCreate(['code' => $type['code']], $type);
        }
    }
}
