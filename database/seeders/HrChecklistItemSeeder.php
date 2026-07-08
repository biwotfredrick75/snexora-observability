<?php

namespace Database\Seeders;

use App\Models\HrChecklistItem;
use Illuminate\Database\Seeder;

class HrChecklistItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            // Onboarding
            ['type' => 'onboarding', 'title' => 'Collect signed offer letter',       'category' => 'HR',      'sort_order' => 1],
            ['type' => 'onboarding', 'title' => 'Collect ID / national ID copy',      'category' => 'HR',      'sort_order' => 2],
            ['type' => 'onboarding', 'title' => 'Collect KRA PIN, NSSF, SHIF details','category' => 'HR',      'sort_order' => 3],
            ['type' => 'onboarding', 'title' => 'Set up payroll / bank details',      'category' => 'Finance', 'sort_order' => 4],
            ['type' => 'onboarding', 'title' => 'Create system login account',       'category' => 'IT',      'sort_order' => 5],
            ['type' => 'onboarding', 'title' => 'Issue laptop / equipment',          'category' => 'IT',      'sort_order' => 6],
            ['type' => 'onboarding', 'title' => 'Assign email address',              'category' => 'IT',      'sort_order' => 7],
            ['type' => 'onboarding', 'title' => 'Orientation / induction session',   'category' => 'HR',      'sort_order' => 8],
            ['type' => 'onboarding', 'title' => 'Introduce to team and manager',      'category' => 'Admin',   'sort_order' => 9],
            ['type' => 'onboarding', 'title' => 'Assign workstation / desk',          'category' => 'Admin',   'sort_order' => 10],

            // Offboarding
            ['type' => 'offboarding', 'title' => 'Receive resignation / termination notice', 'category' => 'HR',      'sort_order' => 1],
            ['type' => 'offboarding', 'title' => 'Conduct exit interview',                    'category' => 'HR',      'sort_order' => 2],
            ['type' => 'offboarding', 'title' => 'Compute final pay / dues',                  'category' => 'Finance', 'sort_order' => 3],
            ['type' => 'offboarding', 'title' => 'Return laptop / equipment',                 'category' => 'IT',      'sort_order' => 4],
            ['type' => 'offboarding', 'title' => 'Revoke system access / disable login',       'category' => 'IT',      'sort_order' => 5],
            ['type' => 'offboarding', 'title' => 'Deactivate email address',                   'category' => 'IT',      'sort_order' => 6],
            ['type' => 'offboarding', 'title' => 'Clear outstanding advances / loans',         'category' => 'Finance', 'sort_order' => 7],
            ['type' => 'offboarding', 'title' => 'Collect company ID / access card',           'category' => 'Admin',   'sort_order' => 8],
            ['type' => 'offboarding', 'title' => 'Update org chart / handover duties',         'category' => 'Admin',   'sort_order' => 9],
        ];

        foreach ($items as $item) {
            HrChecklistItem::firstOrCreate(
                ['type' => $item['type'], 'title' => $item['title']],
                $item
            );
        }
    }
}
