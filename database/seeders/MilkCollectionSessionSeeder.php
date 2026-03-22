<?php

namespace Database\Seeders;

use App\Models\MilkCollectionSession;
use Illuminate\Database\Seeder;

class MilkCollectionSessionSeeder extends Seeder
{
    public function run(): void
    {
        $sessions = [
            ['description' => 'Morning Session',   'start_time' => '05:00', 'end_time' => '10:00', 'active' => true],
            ['description' => 'Midday Session',    'start_time' => '10:00', 'end_time' => '13:00', 'active' => true],
            ['description' => 'Afternoon Session', 'start_time' => '13:00', 'end_time' => '17:00', 'active' => true],
            ['description' => 'Evening Session',   'start_time' => '17:00', 'end_time' => '20:00', 'active' => true],
        ];

        foreach ($sessions as $session) {
            MilkCollectionSession::firstOrCreate(
                ['description' => $session['description']],
                $session
            );
        }
    }
}
