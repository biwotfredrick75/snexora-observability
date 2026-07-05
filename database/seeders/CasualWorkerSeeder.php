<?php

namespace Database\Seeders;

use App\Models\CasualWorker;
use App\Models\CasualWorkerTrade;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CasualWorkerSeeder extends Seeder
{
    public function run(): void
    {
        $tradeId = fn(string $name) => CasualWorkerTrade::where('name', $name)->value('id');

        $workers = [
            ['worker_no' => 'CW001', 'name' => 'James Mwangi',    'national_id' => '12345678', 'phone' => '0712345678', 'trade' => 'Mason',              'mpesa_no' => '0712345678', 'status' => 'active'],
            ['worker_no' => 'CW002', 'name' => 'Grace Njeri',     'national_id' => '23456789', 'phone' => '0723456789', 'trade' => 'Cleaner',            'mpesa_no' => '0723456789', 'status' => 'active'],
            ['worker_no' => 'CW003', 'name' => 'Peter Otieno',    'national_id' => '34567890', 'phone' => '0734567890', 'trade' => 'General Labour',     'mpesa_no' => '0734567890', 'status' => 'active'],
            ['worker_no' => 'CW004', 'name' => 'Mary Wanjiku',    'national_id' => '45678901', 'phone' => '0745678901', 'trade' => 'Loader / Offloader', 'mpesa_no' => '0745678901', 'status' => 'active'],
            ['worker_no' => 'CW005', 'name' => 'John Kamau',      'national_id' => '56789012', 'phone' => '0756789012', 'trade' => 'Carpenter',          'mpesa_no' => '0756789012', 'status' => 'active'],
            ['worker_no' => 'CW006', 'name' => 'Alice Achieng',   'national_id' => '67890123', 'phone' => '0767890123', 'trade' => 'General Labour',     'mpesa_no' => '0767890123', 'status' => 'active'],
            ['worker_no' => 'CW007', 'name' => 'Samuel Kipchoge', 'national_id' => '78901234', 'phone' => '0778901234', 'trade' => 'Electrician',        'mpesa_no' => '0778901234', 'status' => 'active'],
            ['worker_no' => 'CW008', 'name' => 'Faith Chebet',    'national_id' => '89012345', 'phone' => '0789012345', 'trade' => 'Cleaner',            'mpesa_no' => '0789012345', 'status' => 'active'],
            ['worker_no' => 'CW009', 'name' => 'David Mutua',     'national_id' => '90123456', 'phone' => '0790123456', 'trade' => 'Plumber',            'mpesa_no' => '0790123456', 'status' => 'active'],
            ['worker_no' => 'CW010', 'name' => 'Rose Wambui',     'national_id' => '01234567', 'phone' => '0701234567', 'trade' => 'Loader / Offloader', 'mpesa_no' => '0701234567', 'status' => 'active'],
            ['worker_no' => 'CW011', 'name' => 'Kevin Odhiambo',  'national_id' => '11234567', 'phone' => '0711234567', 'trade' => 'Machine Operator',   'mpesa_no' => '0711234567', 'status' => 'active'],
            ['worker_no' => 'CW012', 'name' => 'Esther Wangari',  'national_id' => '22345678', 'phone' => '0722345678', 'trade' => 'General Labour',     'mpesa_no' => '0722345678', 'status' => 'active'],
            ['worker_no' => 'CW013', 'name' => 'Brian Mwenda',    'national_id' => '33456789', 'phone' => '0733456789', 'trade' => 'Painter',            'mpesa_no' => '0733456789', 'status' => 'active'],
            ['worker_no' => 'CW014', 'name' => 'Nancy Auma',      'national_id' => '44567890', 'phone' => '0744567890', 'trade' => 'Security Guard',     'mpesa_no' => '0744567890', 'status' => 'active'],
            ['worker_no' => 'CW015', 'name' => 'Stephen Njoroge', 'national_id' => '55678901', 'phone' => '0755678901', 'trade' => 'Driver',             'mpesa_no' => '0755678901', 'status' => 'inactive'],
        ];

        $now = now();

        foreach ($workers as $w) {
            $tid = $tradeId($w['trade']);

            $worker = CasualWorker::firstOrCreate(
                ['worker_no' => $w['worker_no']],
                [
                    'name'              => $w['name'],
                    'national_id'       => $w['national_id'],
                    'phone'             => $w['phone'],
                    'emergency_contact' => null,
                    'emergency_phone'   => null,
                    'trade_id'          => $tid,
                    'bank_name'         => null,
                    'account_no'        => null,
                    'mpesa_no'          => $w['mpesa_no'],
                    'status'            => $w['status'],
                    'notes'             => null,
                ]
            );

            // Add a pay rate only if none exists yet for this worker
            $hasRate = DB::table('casual_worker_pay_rates')->where('worker_id', $worker->id)->exists();
            if (! $hasRate) {
                $tradeRate = CasualWorkerTrade::where('id', $tid)->value('default_daily_rate') ?? 500.00;

                DB::table('casual_worker_pay_rates')->insert([
                    'worker_id'           => $worker->id,
                    'trade_id'            => $tid,
                    'rate_type'           => 'daily',
                    'rate'                => $tradeRate,
                    'overtime_multiplier' => 1.5,
                    'transport_allowance' => 0,
                    'meal_allowance'      => 0,
                    'effective_from'      => '2026-01-01',
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
            }
        }

        $this->command->info('Casual workers seeded (15 workers with pay rates).');
    }
}
