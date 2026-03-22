<?php

namespace Database\Seeders;

use App\Models\MilkCollectionSession;
use App\Models\MilkRoute;
use Illuminate\Database\Seeder;

class MilkRouteSeeder extends Seeder
{
    public function run(): void
    {
        // Resolve session IDs (seeded before routes)
        $morning   = MilkCollectionSession::where('description', 'Morning Session')->value('id');
        $afternoon = MilkCollectionSession::where('description', 'Afternoon Session')->value('id');
        $evening   = MilkCollectionSession::where('description', 'Evening Session')->value('id');

        $allSessions = array_filter([$morning, $afternoon, $evening]);

        $routes = [
            [
                'route_code' => 'R001',
                'route_name' => 'Northern Route',
                'status'     => 'active',
                'sessions'   => array_values(array_filter([$morning, $afternoon])),
            ],
            [
                'route_code' => 'R002',
                'route_name' => 'Southern Route',
                'status'     => 'active',
                'sessions'   => array_values(array_filter([$morning, $evening])),
            ],
            [
                'route_code' => 'R003',
                'route_name' => 'Eastern Route',
                'status'     => 'active',
                'sessions'   => array_values($allSessions),
            ],
            [
                'route_code' => 'R004',
                'route_name' => 'Western Route',
                'status'     => 'active',
                'sessions'   => array_values(array_filter([$morning])),
            ],
            [
                'route_code' => 'R005',
                'route_name' => 'Central Route',
                'status'     => 'active',
                'sessions'   => array_values($allSessions),
            ],
            [
                'route_code' => 'R006',
                'route_name' => 'Valley Route',
                'status'     => 'inactive',
                'sessions'   => [],
            ],
        ];

        foreach ($routes as $route) {
            MilkRoute::firstOrCreate(
                ['route_code' => $route['route_code']],
                [
                    'route_name'       => $route['route_name'],
                    'status'           => $route['status'],
                    'sessions'         => $route['sessions'],
                    'merged_route_ids' => [],
                ]
            );
        }
    }
}
