<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CompanyPreferencesSeeder extends Seeder
{
    public function run(): void
    {
        // updateOrInsert so existing rows get allow_negative_inventory patched.
        // Without allow_negative_inventory=true, item search filters every
        // item out when stock_movements is empty (fresh install).
        DB::table('company_preferences')->updateOrInsert(
            ['id' => 1],
            [
                'name'                       => 'Nexora ERP Demo Company',
                'address'                    => 'P.O. Box 12345-00100, Nairobi, Kenya',
                'domicile'                   => 'Kenya',
                'phone'                      => '+254 700 000000',
                'fax'                        => null,
                'email'                      => 'admin@nexora.local',
                'bcc_email'                  => null,
                'company_number'             => 'PVT-2025-001',
                'kra_pin'                    => 'P000000000A',
                'currency'                   => 'Kenya Shillings',
                'logo_filename'              => null,
                'auto_revaluation'           => true,
                'timezone_on_reports'        => true,
                'logo_on_reports'            => true,
                'barcodes_on_stocks'         => false,
                'auto_increase_refs'         => true,
                'fiscal_year'                => '2026',
                'fiscal_status'              => 'Active',
                'tax_periods'                => 1,
                'tax_last_period'            => 1,
                'alt_tax_on_docs'            => false,
                'suppress_tax_rates'         => false,
                'allow_negative_inventory'   => true,  // allow dropdowns to show 0-stock items
                'base_price_calc'            => 'No base price list',
                'add_price_from_std_cost'    => null,
                'round_prices'               => 2,
                'module_manufacturing'       => true,
                'module_fixed_assets'        => false,
                'use_dimensions'             => 0,
                'short_name_in_list'         => false,
                'search_item_list'           => true,
                'search_customer_list'       => true,
                'search_supplier_list'       => true,
                'login_timeout'              => 2000,
                'db_scheme_version'          => '2.4.1',
                'updated_at'                 => now(),
            ]
        );

        $this->command->info('Company preferences seeded (allow_negative_inventory=true).');
    }
}
