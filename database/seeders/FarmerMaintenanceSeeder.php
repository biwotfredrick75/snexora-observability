<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class FarmerMaintenanceSeeder extends Seeder
{
    /**
     * Seed all farmer maintenance reference data.
     * Order matters: sessions before routes, routes before stations/guns.
     */
    public function run(): void
    {
        $this->call([
            FarmerBankSeeder::class,
            MilkCollectionSessionSeeder::class,
            MilkCollectionShiftSeeder::class,
            MilkRouteSeeder::class,
            MilkStationSeeder::class,
            MilkGunSeeder::class,
            MilkBuyingPriceTypeSeeder::class,
            MilkPriceSeeder::class,
            CheckoffServiceSeeder::class,
            MilkQaParameterSeeder::class,
        ]);
    }
}
