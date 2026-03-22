<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CustomerSeeder extends Seeder
{
    private static array $FIRST_NAMES = [
        'James', 'John', 'Robert', 'Michael', 'William', 'David', 'Richard', 'Joseph',
        'Mary', 'Patricia', 'Jennifer', 'Linda', 'Barbara', 'Elizabeth', 'Susan', 'Jessica',
        'Alice', 'Brian', 'Carol', 'Daniel', 'Eleanor', 'Frank', 'Grace', 'Henry',
        'Kevin', 'Laura', 'Mark', 'Nancy', 'Oscar', 'Paula', 'Rachel', 'Steven',
        'Agnes', 'Benedict', 'Catherine', 'Dennis', 'Esther', 'Felix', 'Gloria', 'Harold',
        'Wanjiru', 'Kamau', 'Achieng', 'Otieno', 'Mwangi', 'Njoroge', 'Akinyi', 'Mutua',
        'Chebet', 'Koech', 'Rotich', 'Kirui', 'Bett', 'Sang', 'Kiptoo', 'Chepkoech',
        'Amina', 'Hassan', 'Fatuma', 'Ibrahim', 'Zainab', 'Omar', 'Halima', 'Ahmed',
    ];

    private static array $LAST_NAMES = [
        'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis',
        'Mwangi', 'Kamau', 'Ochieng', 'Otieno', 'Wanjiku', 'Njoroge', 'Kipchoge', 'Mutua',
        'Kariuki', 'Kimani', 'Odhiambo', 'Njuguna', 'Chesire', 'Koech', 'Rotich', 'Kirui',
        'Bett', 'Sang', 'Kiptoo', 'Chepkoech', 'Njenga', 'Waweru', 'Gacheru', 'Muthoni',
        'Onyango', 'Ouma', 'Auma', 'Nyambura', 'Wangari', 'Githae', 'Kiprotich', 'Ruto',
        'Hassan', 'Omar', 'Ahmed', 'Ali', 'Farah', 'Abdi', 'Yusuf', 'Mohamed',
    ];

    private static array $TOWNS = [
        'Nairobi', 'Mombasa', 'Kisumu', 'Nakuru', 'Eldoret', 'Thika',
        'Nyeri', 'Meru', 'Kitale', 'Kakamega', 'Garissa', 'Malindi',
        'Machakos', 'Nanyuki', 'Kericho', 'Bungoma', 'Embu', 'Isiolo',
    ];

    private static array $BUSINESS_TYPES = [
        'Enterprises', 'Ltd', 'Agency', 'Traders', 'Supplies', 'Distributors',
        'Holdings', 'Group', 'Associates', 'Solutions', 'Services', 'General Stores',
    ];

    private static array $CURRENCIES = ['KES', 'KES', 'KES', 'KES', 'USD', 'EUR'];

    public function run(): void
    {
        // Fetch existing FK values to use (or null if tables are empty)
        $paymentTermIds    = DB::table('payment_terms')->pluck('id')->toArray();
        $salesTypeIds      = DB::table('sales_types')->pluck('id')->toArray();
        $creditStatusIds   = DB::table('credit_statuses')->pluck('id')->toArray();

        $customers = [];
        $now = now()->toDateTimeString();

        for ($i = 1; $i <= 150; $i++) {
            $first    = self::$FIRST_NAMES[array_rand(self::$FIRST_NAMES)];
            $last     = self::$LAST_NAMES[array_rand(self::$LAST_NAMES)];
            $town     = self::$TOWNS[array_rand(self::$TOWNS)];
            $btype    = self::$BUSINESS_TYPES[array_rand(self::$BUSINESS_TYPES)];

            // Mix of individual and business names
            $name = ($i % 3 === 0)
                ? "$first $last $btype"
                : "$first $last";

            $shortName = strlen($name) > 30
                ? substr($name, 0, 30)
                : $name;

            $debtorNo = 'C' . str_pad($i, 5, '0', STR_PAD_LEFT);
            $pin      = 'P' . str_pad(rand(100000000, 999999999), 9, '0', STR_PAD_LEFT);
            $phone    = '07' . rand(10, 79) . str_pad(rand(0, 9999999), 7, '0', STR_PAD_LEFT);
            $creditLimit = [0, 5000, 10000, 25000, 50000, 100000, 200000][rand(0, 6)];

            $customers[] = [
                'debtor_no'                 => $debtorNo,
                'customer_number'           => $i,
                'name'                      => $name,
                'short_name'                => $shortName,
                'address'                   => 'P.O. Box ' . rand(100, 99999) . ' - ' . rand(10000, 99999) . ' ' . $town,
                'phone'                     => $phone,
                'email'                     => strtolower(str_replace(' ', '.', "$first.$last")) . rand(1, 99) . '@gmail.com',
                'kra_pin'                   => $pin,
                'currency'                  => self::$CURRENCIES[array_rand(self::$CURRENCIES)],
                'payment_terms'             => $paymentTermIds ? $paymentTermIds[array_rand($paymentTermIds)] : null,
                'price_list_id'             => $salesTypeIds   ? $salesTypeIds[array_rand($salesTypeIds)]     : null,
                'credit_status_id'          => $creditStatusIds? $creditStatusIds[array_rand($creditStatusIds)]: null,
                'discount'                  => [0, 0, 0, 2.5, 5, 7.5, 10][rand(0, 6)],
                'prompt_payment_discount'   => [0, 0, 1, 2][rand(0, 3)],
                'credit_limit'              => $creditLimit,
                'current_credit'            => round($creditLimit * (rand(0, 100) / 100), 2),
                'credit_invoices_allowed'   => rand(0, 5),
                'inactive'                  => ($i > 140) ? 1 : 0,
                'general_notes'             => null,
                'dimension_id'              => null,
                'dimension2_id'             => null,
                'created_at'                => $now,
                'updated_at'                => $now,
            ];
        }

        // Insert in chunks
        foreach (array_chunk($customers, 50) as $chunk) {
            DB::table('customers')->insert($chunk);
        }

        $this->command->info('Seeded 150 customers.');
    }
}
