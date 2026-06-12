<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MilkRouteSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('milk_routes')->count() > 0) {
            return;
        }

        // Resolve session IDs — sessions must already exist (seeded by MilkCollectionSessionSeeder)
        $morning   = DB::table('milk_collection_sessions')->where('description', 'Morning Session')->value('id');
        $afternoon = DB::table('milk_collection_sessions')->where('description', 'Afternoon Session')->value('id');
        $evening   = DB::table('milk_collection_sessions')->where('description', 'Evening Session')->value('id');

        $all = array_values(array_filter([$morning, $afternoon, $evening]));

        $now = now();

        // Use DB::table with json_encode so SQL Server gets a plain string, not PHP array
        // (Eloquent JSON cast can fail on SQL Server with certain PDO driver versions)
        DB::table('milk_routes')->insert([
            ['route_code' => 'R001', 'route_name' => 'Northern Route', 'status' => 'active',   'sessions' => json_encode(array_values(array_filter([$morning, $afternoon]))), 'merged_route_ids' => '[]', 'location_id' => null, 'created_at' => $now, 'updated_at' => $now],
            ['route_code' => 'R002', 'route_name' => 'Southern Route', 'status' => 'active',   'sessions' => json_encode(array_values(array_filter([$morning, $evening]))),    'merged_route_ids' => '[]', 'location_id' => null, 'created_at' => $now, 'updated_at' => $now],
            ['route_code' => 'R003', 'route_name' => 'Eastern Route',  'status' => 'active',   'sessions' => json_encode($all),                                                'merged_route_ids' => '[]', 'location_id' => null, 'created_at' => $now, 'updated_at' => $now],
            ['route_code' => 'R004', 'route_name' => 'Western Route',  'status' => 'active',   'sessions' => json_encode(array_values(array_filter([$morning]))),               'merged_route_ids' => '[]', 'location_id' => null, 'created_at' => $now, 'updated_at' => $now],
            ['route_code' => 'R005', 'route_name' => 'Central Route',  'status' => 'active',   'sessions' => json_encode($all),                                                'merged_route_ids' => '[]', 'location_id' => null, 'created_at' => $now, 'updated_at' => $now],
            ['route_code' => 'R006', 'route_name' => 'Valley Route',   'status' => 'inactive', 'sessions' => '[]',                                                             'merged_route_ids' => '[]', 'location_id' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $this->command->info('Milk routes seeded (6 routes).');
    }
}
