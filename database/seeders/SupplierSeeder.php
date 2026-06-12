<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SupplierSeeder extends Seeder
{
    private static array $FIRST_NAMES = [
        'James','John','Robert','Michael','William','David','Richard','Joseph','Thomas','Charles',
        'Mary','Patricia','Jennifer','Linda','Barbara','Elizabeth','Susan','Jessica','Sarah','Karen',
        'Alice','Brian','Carol','Daniel','Eleanor','Frank','Grace','Henry','Irene','Jack',
        'Kevin','Laura','Mark','Nancy','Oscar','Paula','Quentin','Rachel','Steven','Tracy',
        'Ursula','Victor','Wendy','Xavier','Yvonne','Zachary','Agnes','Benedict','Catherine','Dennis',
        'Esther','Felix','Gloria','Harold','Ingrid','Jerome','Kathleen','Leonard','Maureen','Nathaniel',
        'Olivia','Patrick','Queenie','Ronald','Sylvia','Timothy','Ulrich','Valerie','Walter','Xiomara',
    ];

    private static array $LAST_NAMES = [
        'Smith','Johnson','Williams','Brown','Jones','Garcia','Miller','Davis','Rodriguez','Martinez',
        'Hernandez','Lopez','Gonzalez','Wilson','Anderson','Thomas','Taylor','Moore','Jackson','Martin',
        'Lee','Perez','Thompson','White','Harris','Sanchez','Clark','Ramirez','Lewis','Robinson',
        'Walker','Young','Allen','King','Wright','Scott','Torres','Nguyen','Hill','Flores',
        'Green','Adams','Nelson','Baker','Hall','Rivera','Campbell','Mitchell','Carter','Roberts',
        'Mwangi','Kamau','Ochieng','Otieno','Wanjiku','Njoroge','Kipchoge','Akinyi','Mutua','Waweru',
        'Kariuki','Kimani','Odhiambo','Njuguna','Chesire','Koech','Rotich','Kirui','Bett','Sang',
    ];

    private static array $BANKS = [
        'KCB','Equity','Co-operative','NCBA','Standard Chartered',
        'Absa','DTB','Stanbic','I&M','Family',
    ];

    private static array $BRANCHES = [
        'Nairobi','Mombasa','Kisumu','Nakuru','Eldoret',
        'Thika','Nyeri','Meru','Kitale','Kakamega',
    ];

    private static array $ROUTES = [
        'Route-A','Route-B','Route-C','Route-D','Route-E',
        'Route-F','Route-G','Route-H','Route-I','Route-J',
    ];

    private static array $GENDERS = ['Male','Female'];

    private static array $RELATIONSHIPS = ['Spouse','Parent','Sibling','Child','Friend'];

    public function run(): void
    {
        // 10,000 Normal + 20,000 Farmer = 30,000 total
        $specs = [
            ['type' => 'Normal', 'count' => 10000, 'prefix' => 'NRM'],
            ['type' => 'Farmer', 'count' => 20000, 'prefix' => 'FRM'],
        ];

        $globalIndex = 1;
        $chunkSize   = 47; // SQL Server max 2100 params; 44 cols × 47 rows = 2068

        foreach ($specs as $spec) {
            $type   = $spec['type'];
            $count  = $spec['count'];
            $prefix = $spec['prefix'];
            $batch  = [];

            for ($i = 1; $i <= $count; $i++, $globalIndex++) {
                $first   = self::$FIRST_NAMES[array_rand(self::$FIRST_NAMES)];
                $last    = self::$LAST_NAMES[array_rand(self::$LAST_NAMES)];
                $fullName = "$first $last";

                $dob     = date('Y-m-d', strtotime('-' . rand(20, 65) . ' years -' . rand(0, 364) . ' days'));
                $refNo   = str_pad($globalIndex, 6, '0', STR_PAD_LEFT);

                $batch[] = [
                    'supplierName'               => $fullName,
                    'supplierReference'          => "{$prefix}{$refNo}",
                    'memberNumber'               => "MBR{$prefix}{$refNo}",
                    'routeGroupId'               => rand(1, 10),
                    'address'                    => rand(100, 9999) . ' ' . self::$BRANCHES[array_rand(self::$BRANCHES)],
                    'supplierAddress'            => 'P.O. Box ' . rand(1000, 99999) . ' ' . self::$BRANCHES[array_rand(self::$BRANCHES)],
                    'gstNumber'                  => '',
                    'contactPerson'              => $fullName,
                    'supplierAccountNumber'      => 'ACC' . str_pad($globalIndex, 8, '0', STR_PAD_LEFT),
                    'website'                    => '',
                    'bankAccount'                => str_pad(rand(1000000, 9999999), 10, '0', STR_PAD_LEFT),
                    'currencyCode'               => 'KES',
                    'paymentTerms'               => null,
                    'taxIncluded'                => false,
                    'dimensionId'                => 0,
                    'dimension2Id'               => 0,
                    'taxGroupId'                 => null,
                    'creditLimit'                => 0,
                    'purchaseAccount'            => '',
                    'payableAccount'             => '',
                    'paymentDiscountAccount'     => '',
                    'notes'                      => '',
                    'inactive'                   => false,
                    'supplierType'               => $type,
                    'gender'                     => self::$GENDERS[array_rand(self::$GENDERS)],
                    'dateOfBirth'                => $dob,
                    'bankNumber'                 => self::$BANKS[array_rand(self::$BANKS)],
                    'bankBranch'                 => self::$BRANCHES[array_rand(self::$BRANCHES)],
                    'idNumber'                   => str_pad(rand(10000000, 39999999), 8, '0', STR_PAD_LEFT),
                    'nextOfKin'                  => self::$FIRST_NAMES[array_rand(self::$FIRST_NAMES)] . ' ' . self::$LAST_NAMES[array_rand(self::$LAST_NAMES)],
                    'nextOfKinId'                => str_pad(rand(10000000, 39999999), 8, '0', STR_PAD_LEFT),
                    'nextOfKinRelationship'      => self::$RELATIONSHIPS[array_rand(self::$RELATIONSHIPS)],
                    'route'                      => self::$ROUTES[array_rand(self::$ROUTES)],
                    'deductNhif'                 => false,
                    'packingSharesLimit'         => 0,
                    'savingsPerLitre'            => 0,
                    'withholdingTaxAccount'      => null,
                    'nextOfKinTelephone'         => '07' . str_pad(rand(10000000, 99999999), 8, '0', STR_PAD_LEFT),
                    'source'                     => 0,
                    'registrationFeeComplete'    => 0,
                    'sharesLimit'                => 0,
                    'balanceMessageDate'         => date('Y-m-d'),
                    'isVendor'                   => $type === 'Normal',
                    'createdAt'                  => now(),
                ];

                if (count($batch) >= $chunkSize) {
                    DB::table('suppliers')->insert($batch);
                    $batch = [];
                }
            }

            if ($batch) {
                DB::table('suppliers')->insert($batch);
            }

            $this->command->info("Seeded {$count} {$type} suppliers.");
        }

        $this->command->info('Total: 30,000 suppliers seeded.');
    }
}
