<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyPreference extends Model
{
    protected $table = 'company_preferences';

    protected $fillable = [
        'name', 'address', 'domicile', 'phone', 'fax', 'email', 'bcc_email',
        'company_number', 'kra_pin', 'currency', 'debtors_gl_code', 'discount_gl_code', 'logo_filename',
        'auto_revaluation', 'timezone_on_reports', 'logo_on_reports',
        'barcodes_on_stocks', 'auto_increase_refs',
        'fiscal_year', 'fiscal_status', 'tax_periods', 'tax_last_period',
        'alt_tax_on_docs', 'suppress_tax_rates', 'allow_negative_inventory',
        'base_price_calc', 'add_price_from_std_cost', 'round_prices',
        'module_manufacturing', 'module_fixed_assets', 'use_dimensions',
        'short_name_in_list', 'search_item_list', 'search_customer_list',
        'search_supplier_list', 'login_timeout', 'db_scheme_version',
    ];

    protected function casts(): array
    {
        return [
            'auto_revaluation'      => 'boolean',
            'timezone_on_reports'   => 'boolean',
            'logo_on_reports'       => 'boolean',
            'barcodes_on_stocks'    => 'boolean',
            'auto_increase_refs'    => 'boolean',
            'alt_tax_on_docs'       => 'boolean',
            'suppress_tax_rates'         => 'boolean',
            'allow_negative_inventory'   => 'boolean',
            'module_manufacturing'  => 'boolean',
            'module_fixed_assets'   => 'boolean',
            'short_name_in_list'    => 'boolean',
            'search_item_list'      => 'boolean',
            'search_customer_list'  => 'boolean',
            'search_supplier_list'  => 'boolean',
            'tax_periods'           => 'integer',
            'tax_last_period'       => 'integer',
            'round_prices'          => 'integer',
            'use_dimensions'        => 'integer',
            'login_timeout'         => 'integer',
            'add_price_from_std_cost' => 'float',
        ];
    }
}
