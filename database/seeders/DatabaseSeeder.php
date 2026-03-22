<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed roles first
        $this->call(RoleSeeder::class);

        // Then seed permissions
        $this->call(PermissionSeeder::class);

        // Finally seed users
        $this->call(UserSeeder::class);

        // Seed farmer maintenance reference data
        $this->call(FarmerMaintenanceSeeder::class);

        // Seed inventory locations
        $this->call(InventoryLocationSeeder::class);

        // Seed farmers (20 000 records) — run independently if needed
        // $this->call(FarmerSeeder::class);

        // Seed milk collections March 1–15 2026 — requires FarmerSeeder to have run first
        // $this->call(MilkCollectionSeeder::class);

        $this->command->info('');
        $this->command->info('✨ Database seeding completed successfully!');
    }
}
