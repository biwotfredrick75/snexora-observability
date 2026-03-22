<?php

namespace Database\Seeders;

use App\Models\MilkCollectionShift;
use Illuminate\Database\Seeder;

class MilkCollectionShiftSeeder extends Seeder
{
    public function run(): void
    {
        $shifts = [
            ['description' => 'Day Shift',     'start_time' => '06:00', 'end_time' => '14:00', 'active' => true],
            ['description' => 'Night Shift',   'start_time' => '22:00', 'end_time' => '06:00', 'active' => true],
            ['description' => 'Morning Shift', 'start_time' => '05:00', 'end_time' => '13:00', 'active' => true],
            ['description' => 'Evening Shift', 'start_time' => '14:00', 'end_time' => '22:00', 'active' => true],
        ];

        foreach ($shifts as $shift) {
            MilkCollectionShift::firstOrCreate(
                ['description' => $shift['description']],
                $shift
            );
        }
    }
}
