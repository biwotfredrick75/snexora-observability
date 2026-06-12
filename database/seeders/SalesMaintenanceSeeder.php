<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds sales_types, sales_areas, sales_persons, sales_groups,
 * credit_note_reasons, credit_statuses.
 * All are required for sales transactions to work correctly.
 */
class SalesMaintenanceSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // Sales types — price list tiers
        // Use 0/1 for bit columns (SQL Server rejects PHP false/true in raw inserts)
        if (DB::table('sales_types')->count() === 0) {
            DB::table('sales_types')->insert([
                ['type_name' => 'Retail',        'factor' => 1.0000, 'tax_incl' => 0, 'created_at' => $now, 'updated_at' => $now],
                ['type_name' => 'Wholesale',     'factor' => 0.9000, 'tax_incl' => 0, 'created_at' => $now, 'updated_at' => $now],
                ['type_name' => 'Distributor',   'factor' => 0.8000, 'tax_incl' => 0, 'created_at' => $now, 'updated_at' => $now],
                ['type_name' => 'Tax Inclusive', 'factor' => 1.0000, 'tax_incl' => 1, 'created_at' => $now, 'updated_at' => $now],
            ]);
            $this->command->info('  Sales types seeded.');
        }

        // Sales areas
        if (DB::table('sales_areas')->count() === 0) {
            DB::table('sales_areas')->insert([
                ['area_name' => 'Nairobi',          'created_at' => $now, 'updated_at' => $now],
                ['area_name' => 'Central Kenya',    'created_at' => $now, 'updated_at' => $now],
                ['area_name' => 'Coast Region',     'created_at' => $now, 'updated_at' => $now],
                ['area_name' => 'Western Kenya',    'created_at' => $now, 'updated_at' => $now],
                ['area_name' => 'Rift Valley',      'created_at' => $now, 'updated_at' => $now],
                ['area_name' => 'Eastern Kenya',    'created_at' => $now, 'updated_at' => $now],
                ['area_name' => 'Nyanza',           'created_at' => $now, 'updated_at' => $now],
                ['area_name' => 'North Eastern',    'created_at' => $now, 'updated_at' => $now],
            ]);
            $this->command->info('  Sales areas seeded.');
        }

        // Sales persons
        if (DB::table('sales_persons')->count() === 0) {
            DB::table('sales_persons')->insert([
                ['name' => 'Head Office',     'telephone' => null,           'fax' => null, 'email' => null,                   'provision_pct' => 0, 'turnover_break_pt' => 0, 'provision2_pct' => 0, 'username' => 'admin',    'created_at' => $now, 'updated_at' => $now],
                ['name' => 'John Kamau',      'telephone' => '0722000001',   'fax' => null, 'email' => 'j.kamau@nexora.local', 'provision_pct' => 2, 'turnover_break_pt' => 500000, 'provision2_pct' => 3, 'username' => null, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Mary Wanjiku',    'telephone' => '0722000002',   'fax' => null, 'email' => 'm.wanjiku@nexora.local','provision_pct' => 2, 'turnover_break_pt' => 500000, 'provision2_pct' => 3, 'username' => null, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Peter Otieno',    'telephone' => '0722000003',   'fax' => null, 'email' => 'p.otieno@nexora.local', 'provision_pct' => 2, 'turnover_break_pt' => 500000, 'provision2_pct' => 3, 'username' => null, 'created_at' => $now, 'updated_at' => $now],
            ]);
            $this->command->info('  Sales persons seeded.');
        }

        // Sales groups
        if (DB::table('sales_groups')->count() === 0) {
            DB::table('sales_groups')->insert([
                ['group_name' => 'Direct Sales',   'created_at' => $now, 'updated_at' => $now],
                ['group_name' => 'Wholesale',      'created_at' => $now, 'updated_at' => $now],
                ['group_name' => 'Institutional',  'created_at' => $now, 'updated_at' => $now],
                ['group_name' => 'Export',         'created_at' => $now, 'updated_at' => $now],
            ]);
            $this->command->info('  Sales groups seeded.');
        }

        // Credit note reasons
        if (DB::table('credit_note_reasons')->count() === 0) {
            DB::table('credit_note_reasons')->insert([
                ['reason_code' => 'DAM',  'reason_name' => 'Damaged Goods',         'description' => 'Goods received damaged', 'created_at' => $now, 'updated_at' => $now],
                ['reason_code' => 'WRG',  'reason_name' => 'Wrong Item',            'description' => 'Incorrect item delivered', 'created_at' => $now, 'updated_at' => $now],
                ['reason_code' => 'OVR',  'reason_name' => 'Overcharge',            'description' => 'Customer was overcharged', 'created_at' => $now, 'updated_at' => $now],
                ['reason_code' => 'RET',  'reason_name' => 'Goods Returned',        'description' => 'Customer returned goods', 'created_at' => $now, 'updated_at' => $now],
                ['reason_code' => 'EXP',  'reason_name' => 'Expired Product',       'description' => 'Product reached expiry date', 'created_at' => $now, 'updated_at' => $now],
                ['reason_code' => 'QTY',  'reason_name' => 'Quantity Dispute',      'description' => 'Quantity billed vs delivered differs', 'created_at' => $now, 'updated_at' => $now],
                ['reason_code' => 'DISC', 'reason_name' => 'Discount Adjustment',   'description' => 'Discount applied post-invoice', 'created_at' => $now, 'updated_at' => $now],
                ['reason_code' => 'CAN',  'reason_name' => 'Order Cancellation',    'description' => 'Order cancelled after invoicing', 'created_at' => $now, 'updated_at' => $now],
            ]);
            $this->command->info('  Credit note reasons seeded.');
        }

        // Credit statuses
        if (DB::table('credit_statuses')->count() === 0) {
            DB::table('credit_statuses')->insert([
                ['description' => 'Good Standing',  'disallow_invoices' => 0, 'created_at' => $now, 'updated_at' => $now],
                ['description' => 'On Watch',       'disallow_invoices' => 0, 'created_at' => $now, 'updated_at' => $now],
                ['description' => 'On Hold',        'disallow_invoices' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['description' => 'Suspended',      'disallow_invoices' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['description' => 'New Customer',   'disallow_invoices' => 0, 'created_at' => $now, 'updated_at' => $now],
            ]);
            $this->command->info('  Credit statuses seeded.');
        }
    }
}
