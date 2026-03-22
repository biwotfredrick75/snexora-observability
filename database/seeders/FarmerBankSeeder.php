<?php

namespace Database\Seeders;

use App\Models\FarmerBank;
use Illuminate\Database\Seeder;

class FarmerBankSeeder extends Seeder
{
    public function run(): void
    {
        $banks = [
            'Kenya Commercial Bank (KCB)',
            'Equity Bank',
            'Co-operative Bank of Kenya',
            'Absa Bank Kenya',
            'Standard Chartered Bank',
            'National Bank of Kenya',
            'Family Bank',
            'I&M Bank',
            'Diamond Trust Bank (DTB)',
            'Stanbic Bank Kenya',
            'Post Bank Kenya',
            'Faulu Microfinance Bank',
            'KWFT Microfinance Bank',
            'Kenya Post Office Savings Bank',
            'M-Pesa (Safaricom)',
        ];

        foreach ($banks as $name) {
            FarmerBank::firstOrCreate(['name' => $name]);
        }
    }
}
