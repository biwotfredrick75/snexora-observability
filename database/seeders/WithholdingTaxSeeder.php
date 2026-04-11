<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WithholdingTaxSeeder extends Seeder
{
    /**
     * Kenya Revenue Authority (KRA) withholding tax rates.
     * Source: KRA Third Schedule — Income Tax Act Cap 470.
     */
    public function run(): void
    {
        $taxes = [
            // ── Professional / Management fees ───────────────────────────────
            ['description' => 'Professional Fees - Resident',          'tax_rate' => 5.00,  'gl_account' => ''],
            ['description' => 'Professional Fees - Non-Resident',      'tax_rate' => 20.00, 'gl_account' => ''],
            ['description' => 'Management Fees - Resident',            'tax_rate' => 5.00,  'gl_account' => ''],
            ['description' => 'Management Fees - Non-Resident',        'tax_rate' => 20.00, 'gl_account' => ''],
            ['description' => 'Consultancy Fees - Resident',           'tax_rate' => 5.00,  'gl_account' => ''],
            ['description' => 'Consultancy Fees - Non-Resident',       'tax_rate' => 20.00, 'gl_account' => ''],
            // ── Training ─────────────────────────────────────────────────────
            ['description' => 'Training Fees - Resident',              'tax_rate' => 5.00,  'gl_account' => ''],
            ['description' => 'Training Fees - Non-Resident',          'tax_rate' => 20.00, 'gl_account' => ''],
            // ── Commissions ───────────────────────────────────────────────────
            ['description' => 'Commissions - Resident',                'tax_rate' => 5.00,  'gl_account' => ''],
            ['description' => 'Commissions - Non-Resident',            'tax_rate' => 20.00, 'gl_account' => ''],
            // ── Dividends ─────────────────────────────────────────────────────
            ['description' => 'Dividends - Resident',                  'tax_rate' => 5.00,  'gl_account' => ''],
            ['description' => 'Dividends - Non-Resident',              'tax_rate' => 10.00, 'gl_account' => ''],
            // ── Interest ──────────────────────────────────────────────────────
            ['description' => 'Interest - Resident (Bank)',            'tax_rate' => 15.00, 'gl_account' => ''],
            ['description' => 'Interest - Non-Resident',               'tax_rate' => 15.00, 'gl_account' => ''],
            // ── Rent ──────────────────────────────────────────────────────────
            ['description' => 'Rent - Immovable Property (Resident)',  'tax_rate' => 7.50,  'gl_account' => ''],
            ['description' => 'Rent - Immovable Property (Non-Resident)', 'tax_rate' => 30.00, 'gl_account' => ''],
            // ── Royalties ─────────────────────────────────────────────────────
            ['description' => 'Royalties - Resident',                  'tax_rate' => 5.00,  'gl_account' => ''],
            ['description' => 'Royalties - Non-Resident',              'tax_rate' => 20.00, 'gl_account' => ''],
            // ── Winnings / Gambling ───────────────────────────────────────────
            ['description' => 'Winnings / Gambling',                   'tax_rate' => 20.00, 'gl_account' => ''],
            // ── Goods / Supplies (Government contracts) ───────────────────────
            ['description' => 'Supply of Goods - Government (Resident)', 'tax_rate' => 2.00, 'gl_account' => ''],
            // ── Pension ───────────────────────────────────────────────────────
            ['description' => 'Pension / Retirement Annuity',          'tax_rate' => 5.00,  'gl_account' => ''],
            // ── Natural resources ─────────────────────────────────────────────
            ['description' => 'Natural Resource Income - Non-Resident', 'tax_rate' => 20.00, 'gl_account' => ''],
        ];

        foreach ($taxes as $tax) {
            DB::table('withholding_taxes')->updateOrInsert(
                ['description' => $tax['description']],
                array_merge($tax, [
                    'inactive'   => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        $this->command->info('Withholding taxes seeded (' . count($taxes) . ' types).');
    }
}
