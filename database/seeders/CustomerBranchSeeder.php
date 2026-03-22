<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CustomerBranchSeeder extends Seeder
{
    private static array $BRANCH_LABELS = [
        'Head Office', 'Main Branch', 'Nairobi Branch', 'Mombasa Branch',
        'Kisumu Branch', 'Nakuru Branch', 'Eldoret Branch', 'Thika Branch',
        'Nyeri Branch', 'Meru Branch', 'Westlands Office', 'CBD Office',
        'Industrial Area', 'South Branch', 'North Branch', 'East Branch',
        'Warehouse', 'Depot', 'Distribution Centre', 'Retail Outlet',
    ];

    private static array $TOWNS = [
        'Nairobi', 'Mombasa', 'Kisumu', 'Nakuru', 'Eldoret', 'Thika',
        'Nyeri', 'Meru', 'Kitale', 'Kakamega', 'Garissa', 'Malindi',
        'Machakos', 'Nanyuki', 'Kericho', 'Bungoma', 'Embu', 'Isiolo',
    ];

    public function run(): void
    {
        // Fetch existing customer debtor_nos
        $debtorNos = DB::table('customers')->pluck('debtor_no')->toArray();

        if (empty($debtorNos)) {
            $this->command->warn('No customers found — run CustomerSeeder first.');
            return;
        }

        // Fetch FK values for branches
        $salesPersonIds     = DB::table('sales_persons')->pluck('id')->toArray();
        $salesAreaIds       = DB::table('sales_areas')->pluck('id')->toArray();
        $salesGroupIds      = DB::table('sales_groups')->pluck('id')->toArray();
        $locationIds        = DB::table('inventory_locations')->pluck('id')->toArray();
        $shippingCompanyIds = DB::table('shipping_companies')->pluck('id')->toArray();
        $taxGroupIds        = DB::table('tax_groups')->pluck('id')->toArray();

        $branches = [];
        $now = now()->toDateTimeString();

        foreach ($debtorNos as $index => $debtorNo) {
            // Each customer gets 1–3 branches (every customer guaranteed at least 1)
            $branchCount = ($index % 4 === 0) ? 3 : (($index % 2 === 0) ? 2 : 1);

            $usedLabels = [];
            for ($b = 0; $b < $branchCount; $b++) {
                // Pick a unique label per customer
                do {
                    $label = self::$BRANCH_LABELS[array_rand(self::$BRANCH_LABELS)];
                } while (in_array($label, $usedLabels));
                $usedLabels[] = $label;

                $town    = self::$TOWNS[array_rand(self::$TOWNS)];
                $phone   = '07' . rand(10, 79) . str_pad(rand(0, 9999999), 7, '0', STR_PAD_LEFT);
                $phone2  = '07' . rand(10, 79) . str_pad(rand(0, 9999999), 7, '0', STR_PAD_LEFT);

                $branches[] = [
                    'debtor_no'              => $debtorNo,
                    'branch_name'            => $label,
                    'branch_short_name'      => substr($label, 0, 20),
                    'phone'                  => $phone,
                    'secondary_phone'        => $phone2,
                    'fax'                    => null,
                    'email'                  => null,
                    'deliver_to'             => "$label, $town",
                    'address'                => 'P.O. Box ' . rand(100, 99999) . ' ' . $town,
                    'mailing_address'        => 'P.O. Box ' . rand(100, 99999) . ' - ' . rand(10000, 99999) . ' ' . $town,
                    'billing_address'        => null,
                    'contact_person'         => null,
                    'document_language'      => 'Customer default',
                    'sales_person_id'        => $salesPersonIds    ? $salesPersonIds[array_rand($salesPersonIds)]       : null,
                    'sales_area_id'          => $salesAreaIds      ? $salesAreaIds[array_rand($salesAreaIds)]           : null,
                    'sales_group_id'         => $salesGroupIds     ? $salesGroupIds[array_rand($salesGroupIds)]         : null,
                    'location_id'            => $locationIds       ? $locationIds[array_rand($locationIds)]             : null,
                    'shipping_company_id'    => $shippingCompanyIds? $shippingCompanyIds[array_rand($shippingCompanyIds)]: null,
                    'tax_group_id'           => $taxGroupIds       ? $taxGroupIds[array_rand($taxGroupIds)]             : null,
                    'sales_account'          => null,
                    'sales_discount_account' => null,
                    'ar_account'             => null,
                    'prompt_discount_account'=> null,
                    'bank_account_number'    => null,
                    'general_notes'          => null,
                    'created_at'             => $now,
                    'updated_at'             => $now,
                ];
            }
        }

        // Insert in chunks
        $total = count($branches);
        foreach (array_chunk($branches, 100) as $chunk) {
            DB::table('customer_branches')->insert($chunk);
        }

        $this->command->info("Seeded {$total} customer branches.");
    }
}
