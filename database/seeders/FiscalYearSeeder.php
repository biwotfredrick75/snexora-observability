<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FiscalYearSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('fiscal_years')->count() > 0) {
            return;
        }

        $now = now();
        DB::table('fiscal_years')->insert([
            [
                'begin_date'  => '2025-01-01',
                'end_date'    => '2025-12-31',
                'is_closed'   => true,
                'is_current'  => false,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'begin_date'  => '2026-01-01',
                'end_date'    => '2026-12-31',
                'is_closed'   => false,
                'is_current'  => true,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [
                'begin_date'  => '2027-01-01',
                'end_date'    => '2027-12-31',
                'is_closed'   => false,
                'is_current'  => false,
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
        ]);

        $this->command->info('Fiscal years seeded (2025–2027, 2026 is current).');
    }
}
