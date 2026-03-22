<?php

namespace Database\Seeders;

use App\Models\MilkRoute;
use App\Models\MilkStation;
use Illuminate\Database\Seeder;

class MilkStationSeeder extends Seeder
{
    public function run(): void
    {
        $routeMap = MilkRoute::pluck('id', 'route_code');

        $stations = [
            // Northern Route
            ['route_code' => 'R001', 'station_name' => 'Kiambu Collection Point',   'status' => 'active'],
            ['route_code' => 'R001', 'station_name' => 'Thika Road Junction',        'status' => 'active'],
            ['route_code' => 'R001', 'station_name' => 'Gatundu Station',            'status' => 'active'],
            ['route_code' => 'R001', 'station_name' => 'Limuru Dairy Point',         'status' => 'active'],

            // Southern Route
            ['route_code' => 'R002', 'station_name' => 'Athi River Station',        'status' => 'active'],
            ['route_code' => 'R002', 'station_name' => 'Kajiado Collection Centre',  'status' => 'active'],
            ['route_code' => 'R002', 'station_name' => 'Namanga Border Point',       'status' => 'inactive'],

            // Eastern Route
            ['route_code' => 'R003', 'station_name' => 'Machakos Station',          'status' => 'active'],
            ['route_code' => 'R003', 'station_name' => 'Kitui Collection Centre',    'status' => 'active'],
            ['route_code' => 'R003', 'station_name' => 'Mwingi Dairy Point',         'status' => 'active'],

            // Western Route
            ['route_code' => 'R004', 'station_name' => 'Eldoret Collection Centre',  'status' => 'active'],
            ['route_code' => 'R004', 'station_name' => 'Kapsabet Station',           'status' => 'active'],
            ['route_code' => 'R004', 'station_name' => 'Nandi Hills Point',          'status' => 'active'],

            // Central Route
            ['route_code' => 'R005', 'station_name' => 'Nyeri Dairy Hub',           'status' => 'active'],
            ['route_code' => 'R005', 'station_name' => 'Karatina Station',           'status' => 'active'],
            ['route_code' => 'R005', 'station_name' => 'Nanyuki Collection Point',   'status' => 'active'],
            ['route_code' => 'R005', 'station_name' => 'Meru Dairy Centre',          'status' => 'active'],
        ];

        foreach ($stations as $s) {
            $routeId = $routeMap[$s['route_code']] ?? null;
            MilkStation::firstOrCreate(
                ['station_name' => $s['station_name']],
                ['route_id' => $routeId, 'status' => $s['status']]
            );
        }
    }
}
