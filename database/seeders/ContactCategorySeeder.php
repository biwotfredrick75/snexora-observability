<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContactCategorySeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('contact_categories')->count() > 0) {
            return;
        }

        $now = now();
        DB::table('contact_categories')->insert([
            // Customer contacts
            ['category_type' => 'Customer', 'category_subtype' => 'General',    'short_name' => 'CUST-GEN',  'description' => 'General customer contact',         'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['category_type' => 'Customer', 'category_subtype' => 'Accounts',   'short_name' => 'CUST-ACC',  'description' => 'Customer accounts payable contact', 'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['category_type' => 'Customer', 'category_subtype' => 'Purchasing', 'short_name' => 'CUST-PUR',  'description' => 'Customer purchasing contact',       'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['category_type' => 'Customer', 'category_subtype' => 'Technical',  'short_name' => 'CUST-TECH', 'description' => 'Customer technical contact',        'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            // Supplier contacts
            ['category_type' => 'Supplier', 'category_subtype' => 'General',    'short_name' => 'SUPP-GEN',  'description' => 'General supplier contact',          'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['category_type' => 'Supplier', 'category_subtype' => 'Accounts',   'short_name' => 'SUPP-ACC',  'description' => 'Supplier accounts receivable',      'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['category_type' => 'Supplier', 'category_subtype' => 'Sales',      'short_name' => 'SUPP-SAL',  'description' => 'Supplier sales representative',     'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            // Farmer contacts
            ['category_type' => 'Farmer',   'category_subtype' => 'NextOfKin',  'short_name' => 'FARM-NOK',  'description' => 'Farmer next of kin',                'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['category_type' => 'Farmer',   'category_subtype' => 'General',    'short_name' => 'FARM-GEN',  'description' => 'General farmer contact',            'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $this->command->info('Contact categories seeded (9 categories).');
    }
}
