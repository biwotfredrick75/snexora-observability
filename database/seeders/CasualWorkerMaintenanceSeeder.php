<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class CasualWorkerMaintenanceSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CasualWorkerTradeSeeder::class,
            CasualWorkerSeeder::class,
        ]);
    }
}
