<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GlSettingsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('gl_settings')->updateOrInsert(
            ['id' => 1],
            [
            'past_due_days_interval'          => 30,
            'accounts_type'                   => 'numeric',
            'retained_earnings_account'       => null,
            'profit_loss_year_account'        => null,
            'exchange_variances_account'      => null,
            'bank_charges_account'            => null,
            'tax_deduction_account'           => null,
            'tax_algorithm'                   => 'taxes_from_totals',
            'dimension_required_after'        => 20,
            'default_credit_limit'            => 1000.00,
            'default_credit_invoices'         => 1.00,
            'invoice_identification'          => 'reference',
            'accumulate_batch_shipping'       => false,
            'print_item_image_on_quote'       => false,
            'legal_text_on_invoice'           => null,
            'shipping_charged_account'        => null,
            'deferred_income_account'         => null,
            'receivable_account'              => null,
            'sales_account'                   => null,
            'sales_discount_account'          => null,
            'prompt_payment_discount_account' => null,
            'quote_valid_days'                => 30,
            'delivery_required_by'            => 1,
            'delivery_over_receive_allowance' => 10.0,
            'invoice_over_charge_allowance'   => 10.0,
            'allow_negative_inventory'        => true,
            'allow_negative_prices'           => false,
            'updated_at'                      => now(),
        ]);

        $this->command->info('GL settings seeded (allow_negative_inventory=true).');
    }
}
