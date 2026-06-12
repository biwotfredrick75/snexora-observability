<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShippingCompanySeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('shipping_companies')->count() > 0) {
            return;
        }

        $now = now();
        DB::table('shipping_companies')->insert([
            ['name' => 'Own Vehicle',        'contact_person' => null,             'phone' => null,           'secondary_phone' => null, 'address' => null,                  'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'G4S Kenya',          'contact_person' => 'Operations',     'phone' => '+254 700 100001', 'secondary_phone' => null, 'address' => 'Nairobi, Kenya',    'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Wells Fargo Kenya',  'contact_person' => 'Dispatch',       'phone' => '+254 700 100002', 'secondary_phone' => null, 'address' => 'Nairobi, Kenya',    'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Sendy Logistics',    'contact_person' => 'Customer Care',  'phone' => '+254 700 100003', 'secondary_phone' => null, 'address' => 'Nairobi, Kenya',    'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'DHL Kenya',          'contact_person' => 'Courier Desk',   'phone' => '+254 700 100004', 'secondary_phone' => null, 'address' => 'JKIA Nairobi',      'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Customer Collect',   'contact_person' => null,             'phone' => null,           'secondary_phone' => null, 'address' => null,                  'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $this->command->info('Shipping companies seeded (6 companies).');
    }
}
