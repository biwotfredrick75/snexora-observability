<?php

namespace Database\Seeders;

use App\Models\MilkGun;
use App\Models\MilkRoute;
use Illuminate\Database\Seeder;

class MilkGunSeeder extends Seeder
{
    public function run(): void
    {
        $routeMap = MilkRoute::pluck('id', 'route_code');

        $guns = [
            ['route_code' => 'R001', 'grader' => 'John Kamau',   'gun_no' => 'GUN-001', 'capacity' => 40.00, 'status' => 'active'],
            ['route_code' => 'R001', 'grader' => 'James Njoroge', 'gun_no' => 'GUN-002', 'capacity' => 50.00, 'status' => 'active'],
            ['route_code' => 'R001', 'grader' => 'Peter Mwangi',  'gun_no' => 'GUN-003', 'capacity' => 30.00, 'status' => 'active'],

            ['route_code' => 'R002', 'grader' => 'Samuel Omondi', 'gun_no' => 'GUN-004', 'capacity' => 40.00, 'status' => 'active'],
            ['route_code' => 'R002', 'grader' => 'David Kipchoge','gun_no' => 'GUN-005', 'capacity' => 45.00, 'status' => 'active'],

            ['route_code' => 'R003', 'grader' => 'Joseph Mutua',  'gun_no' => 'GUN-006', 'capacity' => 50.00, 'status' => 'active'],
            ['route_code' => 'R003', 'grader' => 'Michael Kibet', 'gun_no' => 'GUN-007', 'capacity' => 35.00, 'status' => 'inactive'],

            ['route_code' => 'R004', 'grader' => 'Francis Rotich','gun_no' => 'GUN-008', 'capacity' => 60.00, 'status' => 'active'],
            ['route_code' => 'R004', 'grader' => 'Alex Kosgei',   'gun_no' => 'GUN-009', 'capacity' => 40.00, 'status' => 'active'],

            ['route_code' => 'R005', 'grader' => 'Charles Kariuki','gun_no' => 'GUN-010', 'capacity' => 50.00, 'status' => 'active'],
            ['route_code' => 'R005', 'grader' => 'Henry Waweru',   'gun_no' => 'GUN-011', 'capacity' => 45.00, 'status' => 'active'],
            ['route_code' => 'R005', 'grader' => 'Brian Njeru',    'gun_no' => 'GUN-012', 'capacity' => 30.00, 'status' => 'active'],
        ];

        foreach ($guns as $g) {
            $routeId = $routeMap[$g['route_code']] ?? null;
            MilkGun::firstOrCreate(
                ['gun_no' => $g['gun_no']],
                [
                    'route_id' => $routeId,
                    'grader'   => $g['grader'],
                    'capacity' => $g['capacity'],
                    'status'   => $g['status'],
                ]
            );
        }
    }
}
