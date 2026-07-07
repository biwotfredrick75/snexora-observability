<?php

namespace Database\Seeders;

use App\Models\DebitNoteReason;
use Illuminate\Database\Seeder;

class DebitNoteReasonSeeder extends Seeder
{
    public function run(): void
    {
        $reasons = [
            ['reason_code' => 'undercharge',  'reason_name' => 'Undercharge Correction',   'description' => 'Original invoice billed less than it should have'],
            ['reason_code' => 'additional',   'reason_name' => 'Additional Charges',       'description' => 'Supplementary charge for goods/services after the original invoice'],
            ['reason_code' => 'interest',      'reason_name' => 'Interest / Penalty',        'description' => 'Late payment interest or penalty charge'],
            ['reason_code' => 'other',         'reason_name' => 'Other'],
        ];

        foreach ($reasons as $reason) {
            DebitNoteReason::firstOrCreate(['reason_code' => $reason['reason_code']], $reason);
        }
    }
}
