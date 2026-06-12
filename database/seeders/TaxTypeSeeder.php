<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TaxTypeSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('tax_types')->count() > 0) {
            return;
        }

        $now = now();
        DB::table('tax_types')->insert([
            // Kenya Revenue Authority tax rates
            ['description' => 'VAT 16%',          'default_rate' => 16.0000, 'sales_gl_account' => null, 'purchasing_gl_account' => null, 'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['description' => 'VAT 8% (Fuel)',     'default_rate' => 8.0000,  'sales_gl_account' => null, 'purchasing_gl_account' => null, 'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['description' => 'Zero Rated (0%)',   'default_rate' => 0.0000,  'sales_gl_account' => null, 'purchasing_gl_account' => null, 'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['description' => 'VAT Exempt',        'default_rate' => 0.0000,  'sales_gl_account' => null, 'purchasing_gl_account' => null, 'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['description' => 'Excise Duty 10%',   'default_rate' => 10.0000, 'sales_gl_account' => null, 'purchasing_gl_account' => null, 'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Tax groups — standard VAT group and exempt group
        $vatId    = DB::table('tax_types')->where('description', 'VAT 16%')->value('id');
        $zeroId   = DB::table('tax_types')->where('description', 'Zero Rated (0%)')->value('id');
        $exemptId = DB::table('tax_types')->where('description', 'VAT Exempt')->value('id');

        $groups = [
            ['description' => 'Standard VAT (16%)', 'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['description' => 'Zero Rated',          'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['description' => 'VAT Exempt',          'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
        ];

        foreach ($groups as $group) {
            DB::table('tax_groups')->insertGetId($group);
        }

        $stdGroupId    = DB::table('tax_groups')->where('description', 'Standard VAT (16%)')->value('id');
        $zeroGroupId   = DB::table('tax_groups')->where('description', 'Zero Rated')->value('id');
        $exemptGroupId = DB::table('tax_groups')->where('description', 'VAT Exempt')->value('id');

        DB::table('tax_group_types')->insert([
            ['tax_group_id' => $stdGroupId,    'tax_type_id' => $vatId,    'shipping' => false],
            ['tax_group_id' => $zeroGroupId,   'tax_type_id' => $zeroId,   'shipping' => false],
            ['tax_group_id' => $exemptGroupId, 'tax_type_id' => $exemptId, 'shipping' => false],
        ]);

        $this->command->info('Tax types and groups seeded (VAT 16%, 8%, zero-rated, exempt).');
    }
}
