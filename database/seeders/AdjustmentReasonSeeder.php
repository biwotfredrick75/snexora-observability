<?php

namespace Database\Seeders;

use App\Models\AdjustmentReason;
use Illuminate\Database\Seeder;

class AdjustmentReasonSeeder extends Seeder
{
    public function run(): void
    {
        $reasons = [
            ['code' => 'spillage',   'label' => 'Spillage'],
            ['code' => 'shrinkage',  'label' => 'Shrinkage / Evaporation'],
            ['code' => 'theft',      'label' => 'Theft / Pilferage'],
            ['code' => 'sampling',   'label' => 'QA Sampling Loss'],
            ['code' => 'expiry',     'label' => 'Expiry / Write-off'],
            ['code' => 'damage',     'label' => 'Damage'],
            ['code' => 'consumable', 'label' => 'Consumable Usage'],
            ['code' => 'stocktake',  'label' => 'Stocktake Correction'],
            ['code' => 'other',      'label' => 'Other'],
        ];

        foreach ($reasons as $reason) {
            AdjustmentReason::firstOrCreate(['code' => $reason['code']], $reason);
        }
    }
}
