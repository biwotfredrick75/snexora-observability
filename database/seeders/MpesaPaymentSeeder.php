<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MpesaPaymentSeeder extends Seeder
{
    public function run(): void
    {
        // Skip if already seeded
        if (DB::table('mpesa_payments')->count() > 0) {
            $this->command->info('mpesa_payments already has data — skipping seeder.');
            return;
        }

        $till = '8060202';

        $transactions = [
            ['QC2MMB7KSA', 'Customer Merchant Payment', '20260301083012', 1875.00, $till, 'C00022', '254712001001', 'ACHIENG',   '',       'FARAH'],
            ['RF3TTB8LTP', 'Customer Merchant Payment', '20260301091547', 3200.00, $till, 'C00054', '254722002002', 'ACHIENG',   'NJUGUNA','DISTRIBUTORS'],
            ['SG4UUC9MUQ', 'Customer Merchant Payment', '20260302103824', 980.50,  $till, 'C00001', '254733003003', 'JAMES',     '',       'MWANGI'],
            ['TH5VVD0NVR', 'Customer Merchant Payment', '20260302114200', 5000.00, $till, 'C00022', '254712001001', 'ACHIENG',   '',       'FARAH'],
            ['UJ6WWE1OWS', 'Customer Merchant Payment', '20260303090015', 750.00,  $till, 'C00010', '254700004004', 'FATUMA',    '',       'OMAR'],
            ['VK7XXF2PXT', 'Customer Merchant Payment', '20260303143300', 2400.00, $till, 'C00015', '254711005005', 'PETER',     'K',      'KAMAU'],
            ['WL8YYG3QYU', 'Customer Merchant Payment', '20260304080512', 1200.00, $till, 'C00054', '254722002002', 'NJUGUNA',   '',       'DISTRIBUTORS'],
            ['XM9ZZH4RZV', 'Customer Merchant Payment', '20260305092145', 6300.00, $till, 'C00022', '254712001001', 'ACHIENG',   '',       'FARAH'],
            ['YN0AAI5SAW', 'Customer Merchant Payment', '20260306101830', 450.00,  $till, 'C00008', '254733006006', 'MARY',      'W',      'WANJIRU'],
            ['ZO1BBI6TBX', 'Customer Merchant Payment', '20260307115022', 3750.00, $till, 'C00001', '254733003003', 'JAMES',     '',       'MWANGI'],
            ['AP2CCJ7UCY', 'Customer Merchant Payment', '20260310083500', 890.00,  $till, 'C00015', '254711005005', 'PETER',     'K',      'KAMAU'],
            ['BQ3DDK8VDZ', 'Customer Merchant Payment', '20260311094712', 2100.00, $till, 'C00010', '254700004004', 'FATUMA',    '',       'OMAR'],
            ['CR4EEL9WEA', 'Customer Merchant Payment', '20260312102048', 560.00,  $till, 'C00008', '254733006006', 'MARY',      'W',      'WANJIRU'],
            ['DS5FFM0XFB', 'Customer Merchant Payment', '20260313090315', 4500.00, $till, 'C00054', '254722002002', 'ACHIENG',   'NJUGUNA','DISTRIBUTORS'],
            ['ET6GGN1YGC', 'Customer Merchant Payment', '20260314085100', 1650.00, $till, 'C00022', '254712001001', 'ACHIENG',   '',       'FARAH'],
            ['FU7HHO2ZHD', 'Customer Merchant Payment', '20260317093000', 7200.00, $till, 'C00001', '254733003003', 'JAMES',     '',       'MWANGI'],
            ['GV8IIP3AIE', 'Customer Merchant Payment', '20260318104512', 330.00,  $till, 'C00015', '254711005005', 'PETER',     'K',      'KAMAU'],
            ['HW9JJQ4BJF', 'Customer Merchant Payment', '20260318143800', 1800.00, $till, 'C00010', '254700004004', 'FATUMA',    '',       'OMAR'],
            ['IX0KKR5CKG', 'Customer Merchant Payment', '20260319091500', 2750.00, $till, 'C00054', '254722002002', 'NJUGUNA',   '',       'DISTRIBUTORS'],
            ['JY1LLS6DLH', 'Customer Merchant Payment', '20260319163000', 500.00,  $till, 'C00008', '254733006006', 'MARY',      'W',      'WANJIRU'],
        ];

        $rows = [];
        foreach ($transactions as $tx) {
            [$transId, $txType, $transTime, $amount, $shortCode, $bilRef, $msisdn, $fn, $mn, $ln] = $tx;

            $rows[] = [
                'TransactionType'   => $txType,
                'TransID'           => $transId,
                'TransTime'         => $transTime,
                'TransAmount'       => $amount,
                'BusinessShortCode' => $shortCode,
                'BilRef'            => $bilRef,
                'MSISDN'            => $msisdn,
                'First_Name'        => $fn,
                'Middle_Name'       => $mn,
                'Last_Name'         => $ln,
                'OrgAccountBalance' => round(mt_rand(100000, 500000) / 100, 2),
                'custBalance'       => 0,
                'allocated'         => 0,
                'trans_date'        => date('Y-m-d H:i:s', mktime(
                    (int) substr($transTime, 8, 2),
                    (int) substr($transTime, 10, 2),
                    (int) substr($transTime, 12, 2),
                    (int) substr($transTime, 4, 2),
                    (int) substr($transTime, 6, 2),
                    (int) substr($transTime, 0, 4),
                )),
                'transfer_user'     => '',
                'transfer_time'     => null,
                'debtor_no'         => null,
                'payment_id'        => null,
            ];
        }

        DB::table('mpesa_payments')->insert($rows);
        $this->command->info('Inserted ' . count($rows) . ' M-Pesa payment records.');
    }
}
