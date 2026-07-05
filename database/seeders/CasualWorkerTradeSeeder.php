<?php

namespace Database\Seeders;

use App\Models\CasualWorkerTrade;
use Illuminate\Database\Seeder;

class CasualWorkerTradeSeeder extends Seeder
{
    public function run(): void
    {
        $trades = [
            ['name' => 'General Labour',     'default_daily_rate' =>  500.00, 'sort_order' =>  1],
            ['name' => 'Mason',              'default_daily_rate' =>  800.00, 'sort_order' =>  2],
            ['name' => 'Carpenter',          'default_daily_rate' =>  850.00, 'sort_order' =>  3],
            ['name' => 'Plumber',            'default_daily_rate' =>  900.00, 'sort_order' =>  4],
            ['name' => 'Electrician',        'default_daily_rate' =>  950.00, 'sort_order' =>  5],
            ['name' => 'Painter',            'default_daily_rate' =>  700.00, 'sort_order' =>  6],
            ['name' => 'Welder',             'default_daily_rate' =>  900.00, 'sort_order' =>  7],
            ['name' => 'Cleaner',            'default_daily_rate' =>  450.00, 'sort_order' =>  8],
            ['name' => 'Gardener',           'default_daily_rate' =>  450.00, 'sort_order' =>  9],
            ['name' => 'Security Guard',     'default_daily_rate' =>  600.00, 'sort_order' => 10],
            ['name' => 'Driver',             'default_daily_rate' =>  750.00, 'sort_order' => 11],
            ['name' => 'Machine Operator',   'default_daily_rate' =>  850.00, 'sort_order' => 12],
            ['name' => 'Loader / Offloader', 'default_daily_rate' =>  550.00, 'sort_order' => 13],
        ];

        foreach ($trades as $data) {
            CasualWorkerTrade::updateOrCreate(['name' => $data['name']], $data);
        }

        $this->command->info('Casual worker trades seeded (13 trades).');
    }
}
