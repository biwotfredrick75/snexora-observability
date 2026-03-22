<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InventoryLocationSeeder extends Seeder
{
    public function run(): void
    {
        $locations = [
            // Main Factory
            [
                'code'          => 'MF001',
                'name'          => 'Main Factory',
                'type'          => 'Main Factory',
                'contact'       => 'Factory Manager',
                'phone'         => '+254 700 100 001',
                'returns_store' => false,
                'loading_order' => true,
                'inactive'      => false,
            ],

            // Coolers
            [
                'code'          => 'CLR001',
                'name'          => 'Cooler Unit 1',
                'type'          => 'Cooler',
                'contact'       => 'Cooler Attendant',
                'phone'         => '+254 700 100 002',
                'returns_store' => false,
                'loading_order' => false,
                'inactive'      => false,
            ],
            [
                'code'          => 'CLR002',
                'name'          => 'Cooler Unit 2',
                'type'          => 'Cooler',
                'contact'       => 'Cooler Attendant',
                'phone'         => '+254 700 100 003',
                'returns_store' => false,
                'loading_order' => false,
                'inactive'      => false,
            ],

            // Grader Stations
            [
                'code'          => 'GRD001',
                'name'          => 'Grader Station — Kikuyu',
                'type'          => 'Grader',
                'contact'       => 'John Kamau',
                'phone'         => '+254 700 200 001',
                'returns_store' => false,
                'loading_order' => false,
                'inactive'      => false,
            ],
            [
                'code'          => 'GRD002',
                'name'          => 'Grader Station — Kiambu',
                'type'          => 'Grader',
                'contact'       => 'Mary Wanjiku',
                'phone'         => '+254 700 200 002',
                'returns_store' => false,
                'loading_order' => false,
                'inactive'      => false,
            ],
            [
                'code'          => 'GRD003',
                'name'          => 'Grader Station — Thika',
                'type'          => 'Grader',
                'contact'       => 'Peter Odhiambo',
                'phone'         => '+254 700 200 003',
                'returns_store' => false,
                'loading_order' => false,
                'inactive'      => false,
            ],

            // Stores
            [
                'code'          => 'ST001',
                'name'          => 'Main Store',
                'type'          => 'STORE',
                'contact'       => 'Store Keeper',
                'phone'         => '+254 700 300 001',
                'returns_store' => false,
                'loading_order' => true,
                'inactive'      => false,
            ],
            [
                'code'          => 'ST002',
                'name'          => 'Returns Store',
                'type'          => 'Returns',
                'contact'       => 'Store Keeper',
                'phone'         => '+254 700 300 002',
                'returns_store' => true,
                'loading_order' => false,
                'inactive'      => false,
            ],

            // Sales Points
            [
                'code'          => 'SLS001',
                'name'          => 'Sales Counter — Nairobi',
                'type'          => 'SALES',
                'contact'       => 'Sales Manager',
                'phone'         => '+254 700 400 001',
                'email'         => 'sales.nbi@company.co.ke',
                'returns_store' => false,
                'loading_order' => false,
                'inactive'      => false,
            ],

            // Vendors
            [
                'code'          => 'VND001',
                'name'          => 'Vendor — Uchumi Supermarket',
                'type'          => 'Vendor',
                'contact'       => 'Procurement Officer',
                'phone'         => '+254 700 500 001',
                'email'         => 'procurement@uchumi.co.ke',
                'returns_store' => false,
                'loading_order' => false,
                'inactive'      => false,
            ],
            [
                'code'          => 'VND002',
                'name'          => 'Vendor — Naivas Supermarket',
                'type'          => 'Vendor',
                'contact'       => 'Procurement Officer',
                'phone'         => '+254 700 500 002',
                'email'         => 'procurement@naivas.co.ke',
                'returns_store' => false,
                'loading_order' => false,
                'inactive'      => false,
            ],
        ];

        foreach ($locations as $loc) {
            DB::table('inventory_locations')->updateOrInsert(
                ['code' => $loc['code']],
                array_merge($loc, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
