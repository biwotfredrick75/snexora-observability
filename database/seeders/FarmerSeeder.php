<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FarmerSeeder extends Seeder
{
    // Kenyan first names
    private const FIRST_NAMES = [
        'James','John','Peter','David','Paul','Samuel','Daniel','Joseph','Michael','Robert',
        'Moses','Isaac','George','Charles','Francis','Patrick','Emmanuel','William','Thomas','Simon',
        'Grace','Mary','Faith','Hope','Joyce','Alice','Esther','Sarah','Ruth','Mercy',
        'Agnes','Rose','Jane','Ann','Lucy','Catherine','Margaret','Elizabeth','Caroline','Beatrice',
        'Wanjiru','Kamau','Mwangi','Njoroge','Kariuki','Kimani','Gitonga','Muthoni','Njeru','Waweru',
        'Odhiambo','Otieno','Owino','Achieng','Adhiambo','Akoth','Atieno','Awuor','Moraa','Bosire',
        'Mutua','Kioko','Ndungu','Muthama','Mueke','Musyoka','Wambua','Mutiso','Nzomo','Kilonzo',
        'Kipchoge','Chebet','Ruto','Koech','Chepkemoi','Bett','Sang','Rotich','Korir','Kiplimo',
        'Hassan','Ahmed','Fatuma','Mwenda','Njagi','Karimi','Mugo','Gitau','Kinyua','Gacheru',
    ];

    // Kenyan surnames
    private const SURNAMES = [
        'Kamau','Wanjiru','Mwangi','Njoroge','Kariuki','Kimani','Gitonga','Muthoni','Njeru','Waweru',
        'Odhiambo','Otieno','Owino','Achieng','Adhiambo','Akoth','Atieno','Awuor','Moraa','Bosire',
        'Mutua','Kioko','Ndungu','Muthama','Mueke','Musyoka','Wambua','Mutiso','Nzomo','Kilonzo',
        'Kipchoge','Chebet','Ruto','Koech','Chepkemoi','Bett','Sang','Rotich','Korir','Kiplimo',
        'Njagi','Karimi','Mugo','Gitau','Kinyua','Gacheru','Kabiru','Kairu','Njoroge','Gitahi',
        'Muthee','Macharia','Gathoni','Wanjiku','Thuku','Gachie','Njoroge','Nganga','Kimunya','Muoria',
        'Onyango','Okello','Okoth','Ochieng','Odero','Obiero','Odhiambo','Ojwang','Ouma','Omondi',
        'Makau','Mumo','Mbuvi','Mutuku','Mavuti','Ngolo','Nzioki','Mbole','Matata','Kimanzi',
    ];

    private const COUNTIES = [
        'Kiambu','Muranga','Nyeri','Kirinyaga','Nyandarua',
        'Nakuru','Kericho','Bomet','Nandi','Uasin Gishu',
        'Kisumu','Siaya','Homa Bay','Migori','Kisii',
        'Meru','Embu','Tharaka Nithi','Isiolo','Laikipia',
    ];

    private const VILLAGES = [
        'Githunguri','Limuru','Thika','Ruiru','Kikuyu',
        'Karatina','Othaya','Mukurweini','Mathioya','Kangema',
        'Kericho Town','Litein','Sotik','Bomet Town','Mulot',
        'Kisumu Central','Nyando','Muhoroni','Ahero','Awasi',
        'Meru Town','Nkubu','Chuka','Siakago','Runyenjes',
    ];

    public function run(): void
    {
        $total    = 20_000;
        $chunk    = 1_000;
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

        $this->command->info("Seeding {$total} farmers in chunks of {$chunk}…");
        $bar = $this->command->getOutput()->createProgressBar($total);
        $bar->start();

        $batch = [];
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

            $batch[] = [
                'farmer_no'      => 'FRM-' . str_pad($i, 5, '0', STR_PAD_LEFT),
                'member_no'      => $memberNo,
                'full_name'      => $fullName,
                'short_name'     => strtoupper(substr($firstName, 0, 1) . '. ' . $surname),
                'gender'         => $gender,
                'dob'            => $dob,
                'phone'          => $phone,
                'id_no'          => $idNo,
                'currency'       => 'KES',
                'status'         => $statuses[array_rand($statuses)],
                'route_id'       => $routeId,
                'bank_id'        => $bankId,
                'account_no'     => (string) mt_rand(1000000000, 9999999999),
                'village'        => $village,
                'county'         => $county,
                'sub_county'     => $village . ' Sub-County',
                'address'        => "P.O Box " . mt_rand(1, 9999) . ", {$county}",
                'created_at'     => $now,
                'updated_at'     => $now,
            ];

            if (count($batch) >= $chunk) {
                DB::table('farmers')->insert($batch);
                $bar->advance(count($batch));
                $batch = [];
            }
        }

        if (!empty($batch)) {
            DB::table('farmers')->insert($batch);
            $bar->advance(count($batch));
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info("✅ {$total} farmers seeded.");
    }
}
