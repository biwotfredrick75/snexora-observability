<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaymentTermSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('payment_terms')->count() > 0) {
            return;
        }

        $now = now();
        DB::table('payment_terms')->insert([
            ['description' => 'Cash on Delivery',  'type' => 'cash',         'due_after_days' => 0,  'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['description' => 'Prepayment',        'type' => 'prepayment',   'due_after_days' => 0,  'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['description' => 'Net 7 Days',        'type' => 'after_days',   'due_after_days' => 7,  'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['description' => 'Net 14 Days',       'type' => 'after_days',   'due_after_days' => 14, 'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['description' => 'Net 30 Days',       'type' => 'after_days',   'due_after_days' => 30, 'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['description' => 'Net 60 Days',       'type' => 'after_days',   'due_after_days' => 60, 'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['description' => 'Net 90 Days',       'type' => 'after_days',   'due_after_days' => 90, 'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
            ['description' => 'End of Month',      'type' => 'end_of_month', 'due_after_days' => null, 'inactive' => false, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $this->command->info('Payment terms seeded (8 terms).');
    }
}
