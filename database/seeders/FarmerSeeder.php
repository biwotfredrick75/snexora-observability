<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FarmerSeeder extends Seeder
{
    // Kalenjin given names (mixed male/female — Chep-/Jep- prefixes are
    // conventionally female, Kip- conventionally male)
    private const FIRST_NAMES = [
        'Kiprotich','Kipchumba','Kiplagat','Kiprop','Kiptoo','Kibet','Kipruto','Kiprono','Kigen','Kandie',
        'Kipkemboi','Kipsang','Kiptum','Kimutai','Kiprugut','Kibiwott','Kipyego','Kipchirchir','Kiprotichgei','Kimeli',
        'Chepkoech','Chelangat','Jepkosgei','Cherono','Cheptoo','Chesire','Jeptoo','Jerop','Chepngetich','Jelagat',
        'Jemutai','Jeruto','Cherotich','Jerotich','Chepchirchir','Chebet','Jebet','Chepkurui','Jematia','Chepkurgat',
    ];

    // Kalenjin surnames/clan names
    private const SURNAMES = [
        'Cheruiyot','Sang','Bett','Koech','Rotich','Korir','Kirui','Chumo','Tanui','Rono',
        'Yego','Cheboi','Maiyo','Ngeno','Kandagor','Sigei','Kemboi','Barno','Too','Ruto',
        'Kiplimo','Bor','Chirchir','Kigen','Rutto','Boit','Cheruto','Chesang','Kimaiyo','Langat',
    ];

    // Kalenjin-dominant Rift Valley counties, for a demographically
    // coherent dataset alongside the Kalenjin names above.
    private const COUNTIES = [
        'Kericho','Bomet','Nandi','Uasin Gishu','Elgeyo Marakwet',
        'Baringo','West Pokot','Nakuru','Narok','Trans Nzoia',
    ];

    private const VILLAGES = [
        'Kericho Town','Litein','Sotik','Bomet Town','Mulot',
        'Kapsabet','Nandi Hills','Eldoret','Iten','Kabarnet',
        'Kapenguria','Kitale','Narok Town','Molo','Londiani',
    ];

    public function run(): void
    {
        if (DB::table('farmers')->count() > 0) {
            return;
        }

        $total    = 30;
        $chunk    = 100; // SQL Server max 2100 params; 19 cols × 100 rows = 1900
        $now      = now()->toDateTimeString();

        // Pre-load reference IDs
        $routeIds = DB::table('milk_routes')->pluck('id')->toArray();
        $bankIds  = DB::table('farmer_banks')->pluck('id')->toArray();

        if (empty($routeIds)) {
            $this->command->warn('No milk routes found — farmers will have null route_id.');
        }

        $firstNames = self::FIRST_NAMES;
        $surnames   = self::SURNAMES;
        $counties   = self::COUNTIES;
        $villages   = self::VILLAGES;
        $genders    = ['M', 'M', 'M', 'F', 'F'];                // ~60 % male
        $statuses   = ['active','active','active','active','pending']; // ~80 % active

        // Every farmer needs a linked customer account (same conversion
        // CustomerController::store does manually via farmer_no) so seeded
        // farmers can be invoiced/sold to (e.g. grader milk resale) without a
        // separate manual "convert to customer" pass over 20k records.
        $nextCustomerNumber = (int) (DB::table('customers')->max('customer_number') ?? 0) + 1;

        $this->command->info("Seeding {$total} farmers (+ linked customer accounts) in chunks of {$chunk}…");
        $bar = $this->command->getOutput()->createProgressBar($total);
        $bar->start();

        $batch         = [];
        $customerBatch = [];
        $branchBatch   = [];
        for ($i = 1; $i <= $total; $i++) {
            $firstName  = $firstNames[array_rand($firstNames)];
            $surname    = $surnames[array_rand($surnames)];
            $fullName   = $firstName . ' ' . $surname;
            $gender     = $genders[array_rand($genders)];
            $routeId    = $routeIds ? $routeIds[array_rand($routeIds)] : null;
            $bankId     = $bankIds  ? $bankIds[array_rand($bankIds)]   : null;
            $county     = $counties[array_rand($counties)];
            $village    = $villages[array_rand($villages)];
            $phone      = '07' . str_pad(mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
            $dob        = date('Y-m-d', mktime(0,0,0, mt_rand(1,12), mt_rand(1,28), mt_rand(1950,2000)));
            $idNo       = (string) mt_rand(10000000, 40000000);
            $memberNo   = 'MBR-' . str_pad($i, 6, '0', STR_PAD_LEFT);

            $farmerNo = 'FRM-' . str_pad($i, 5, '0', STR_PAD_LEFT);
            $status   = $statuses[array_rand($statuses)];
            $address  = "P.O Box " . mt_rand(1, 9999) . ", {$county}";

            $batch[] = [
                'farmer_no'      => $farmerNo,
                'member_no'      => $memberNo,
                'full_name'      => $fullName,
                'short_name'     => strtoupper(substr($firstName, 0, 1) . '. ' . $surname),
                'gender'         => $gender,
                'dob'            => $dob,
                'phone'          => $phone,
                'id_no'          => $idNo,
                'currency'       => 'KES',
                'status'         => $status,
                'route_id'       => $routeId,
                'bank_id'        => $bankId,
                'account_no'     => (string) mt_rand(1000000000, 9999999999),
                'village'        => $village,
                'county'         => $county,
                'sub_county'     => $village . ' Sub-County',
                'address'        => $address,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];

            // farmer_no doubles as debtor_no — it already fits the column
            // (varchar(10)) and keeps the two records trivially joinable.
            $customerBatch[] = [
                'debtor_no'                => $farmerNo,
                'farmer_no'                => $farmerNo,
                'customer_number'          => $nextCustomerNumber++,
                'name'                     => $fullName,
                'short_name'               => strtoupper(substr($firstName, 0, 1) . '. ' . $surname),
                'address'                  => $address,
                'phone'                    => $phone,
                'email'                    => null,
                'kra_pin'                  => null,
                'currency'                 => 'KES',
                'payment_terms'            => null,
                'price_list_id'            => null,
                'discount'                 => 0,
                'credit_limit'             => 0,
                'current_credit'           => 0,
                'credit_status_id'         => null,
                'credit_invoices_allowed'  => 0,
                'prompt_payment_discount'  => 0,
                'general_notes'            => null,
                'dimension_id'             => null,
                'dimension2_id'            => null,
                // Mirrors the farmer's own approval state — a farmer still
                // pending approval shouldn't be sellable-to yet either.
                'inactive'                 => $status === 'active' ? 0 : 1,
                'created_at'               => $now,
                'updated_at'               => $now,
            ];

            // Every customer needs at least one branch to be invoiceable
            // (see CustomerController::store) — without it a farmer-derived
            // customer would be pickable but broken the moment anyone tries
            // to sell/invoice against it.
            $branchBatch[] = [
                'debtor_no'   => $farmerNo,
                'branch_name' => 'Head Office',
                'phone'       => $phone,
                'deliver_to'  => $fullName,
                'address'     => $address,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];

            if (count($batch) >= $chunk) {
                DB::table('farmers')->insert($batch);
                DB::table('customers')->insert($customerBatch);
                DB::table('customer_branches')->insert($branchBatch);
                $bar->advance(count($batch));
                $batch = $customerBatch = $branchBatch = [];
            }
        }

        if (!empty($batch)) {
            DB::table('farmers')->insert($batch);
            DB::table('customers')->insert($customerBatch);
            DB::table('customer_branches')->insert($branchBatch);
            $bar->advance(count($batch));
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info("✅ {$total} farmers seeded, each with a linked customer account.");
    }
}
