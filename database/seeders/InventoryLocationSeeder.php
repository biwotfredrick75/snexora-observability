<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class InventoryLocationSeeder extends Seeder
{
    public function run(): void
    {
        $locations = [
            // Main Factory
            [
                'code'          => 'MF001',
                'name'          => 'Main Factory',
                'type'          => 'Main Factory',
                'contact'       => 'Factory Manager',
                'phone'         => '+254 700 100 001',
                'returns_store' => false,
                'loading_order' => true,
                'inactive'      => false,
            ],

            // Coolers
            [
                'code'          => 'CLR001',
                'name'          => 'Cooler Unit 1',
                'type'          => 'Cooler',
                'contact'       => 'Cooler Attendant',
                'phone'         => '+254 700 100 002',
                'returns_store' => false,
                'loading_order' => false,
                'inactive'      => false,
            ],
            [
                'code'          => 'CLR002',
                'name'          => 'Cooler Unit 2',
                'type'          => 'Cooler',
                'contact'       => 'Cooler Attendant',
                'phone'         => '+254 700 100 003',
                'returns_store' => false,
                'loading_order' => false,
                'inactive'      => false,
            ],

            // Grader Stations
            [
                'code'          => 'GRD001',
                'name'          => 'Grader Station — Kikuyu',
                'type'          => 'Grader',
                'contact'       => 'John Kamau',
                'phone'         => '+254 700 200 001',
                'price_list'    => 'Retail',
                'returns_store' => false,
                'loading_order' => false,
                'inactive'      => false,
            ],
            [
                'code'          => 'GRD002',
                'name'          => 'Grader Station — Kiambu',
                'type'          => 'Grader',
                'contact'       => 'Mary Wanjiku',
                'phone'         => '+254 700 200 002',
                'price_list'    => 'Retail',
                'returns_store' => false,
                'loading_order' => false,
                'inactive'      => false,
            ],
            [
                'code'          => 'GRD003',
                'name'          => 'Grader Station — Thika',
                'type'          => 'Grader',
                'contact'       => 'Peter Odhiambo',
                'phone'         => '+254 700 200 003',
                'price_list'    => 'Retail',
                'returns_store' => false,
                'loading_order' => false,
                'inactive'      => false,
            ],

            // Stores
            [
                'code'          => 'ST001',
                'name'          => 'Main Store',
                'type'          => 'STORE',
                'contact'       => 'Store Keeper',
                'phone'         => '+254 700 300 001',
                'returns_store' => false,
                'loading_order' => true,
                'inactive'      => false,
            ],
            [
                'code'          => 'ST002',
                'name'          => 'Returns Store',
                'type'          => 'Returns',
                'contact'       => 'Store Keeper',
                'phone'         => '+254 700 300 002',
                'returns_store' => true,
                'loading_order' => false,
                'inactive'      => false,
            ],

            // Sales Points
            [
                'code'          => 'SLS001',
                'name'          => 'Sales Counter — Nairobi',
                'type'          => 'SALES',
                'contact'       => 'Sales Manager',
                'phone'         => '+254 700 400 001',
                'email'         => 'sales.nbi@company.co.ke',
                'returns_store' => false,
                'loading_order' => false,
                'inactive'      => false,
            ],

            // Vendors
            [
                'code'          => 'VND001',
                'name'          => 'Vendor — Uchumi Supermarket',
                'type'          => 'Vendor',
                'contact'       => 'Procurement Officer',
                'phone'         => '+254 700 500 001',
                'email'         => 'procurement@uchumi.co.ke',
                'returns_store' => false,
                'loading_order' => false,
                'inactive'      => false,
            ],
            [
                'code'          => 'VND002',
                'name'          => 'Vendor — Naivas Supermarket',
                'type'          => 'Vendor',
                'contact'       => 'Procurement Officer',
                'phone'         => '+254 700 500 002',
                'email'         => 'procurement@naivas.co.ke',
                'returns_store' => false,
                'loading_order' => false,
                'inactive'      => false,
            ],
        ];

        foreach ($locations as $loc) {
            DB::table('inventory_locations')->updateOrInsert(
                ['code' => $loc['code']],
                array_merge($loc, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        $this->seedGraderUsers();
    }

    private function seedGraderUsers(): void
    {
        app()['cache']->forget('spatie.permission.cache');

        // Ensure grader role exists
        $graderRole = Role::firstOrCreate(
            ['name' => 'grader', 'guard_name' => 'api'],
            ['display_name' => 'Grader']
        );

        // Ensure basic grader permissions exist and are assigned
        $graderPermissions = ['view-dashboard', 'view-collection', 'create-collection'];
        foreach ($graderPermissions as $perm) {
            $permission = Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
            if (!$graderRole->hasPermissionTo($permission)) {
                $graderRole->givePermissionTo($permission);
            }
        }

        // Resolve route IDs for assignment (codes from MilkRouteSeeder)
        $routeMap = DB::table('milk_routes')
            ->whereIn('route_code', ['R001', 'R002', 'R003'])
            ->pluck('id', 'route_code');

        // Grader stations — user_id = loc_code, default_store = loc_code, route assigned
        $graders = [
            [
                'loc_code'  => 'GRD001',
                'real_name' => 'John Kamau',
                'phone'     => '+254 700 200 001',
                'password'  => 'Grader@001',
                'route_id'  => $routeMap['R001'] ?? null,
            ],
            [
                'loc_code'  => 'GRD002',
                'real_name' => 'Mary Wanjiku',
                'phone'     => '+254 700 200 002',
                'password'  => 'Grader@002',
                'route_id'  => $routeMap['R002'] ?? null,
            ],
            [
                'loc_code'  => 'GRD003',
                'real_name' => 'Peter Odhiambo',
                'phone'     => '+254 700 200 003',
                'password'  => 'Grader@003',
                'route_id'  => $routeMap['R003'] ?? null,
            ],
        ];

        foreach ($graders as $g) {
            $user = User::firstOrCreate(
                ['user_id' => $g['loc_code']],
                [
                    'email'         => strtolower($g['loc_code']) . '@nexora.local',
                    'password'      => Hash::make($g['password']),
                    'pin'           => Hash::make('1234'),
                    'real_name'     => $g['real_name'],
                    'phone'         => $g['phone'],
                    'language'      => 'en',
                    'theme'         => 'light',
                    'page_size'     => 'A4',
                    'prices_dec'    => 2,
                    'qty_dec'       => 2,
                    'rates_dec'     => 4,
                    'percent_dec'   => 1,
                    'show_gl'       => false,
                    'show_codes'    => false,
                    'show_hints'    => false,
                    'graphic_links' => false,
                    'rep_popup'     => false,
                    'print_profile' => 'default',
                    'def_print_destination' => 0,
                    'def_print_orientation' => 0,
                    'startup_tab'   => 'dashboard',
                    'transaction_days' => 30,
                    'use_date_picker'  => true,
                    'default_store' => $g['loc_code'],
                    'route_id'      => $g['route_id'],
                    'query_size'    => 50,
                    'inactive'      => false,
                ]
            );

            DB::table('model_has_roles')
                ->where('model_type', User::class)
                ->where('model_id', $user->id)
                ->delete();

            DB::table('model_has_roles')->insert([
                'role_id'    => $graderRole->id,
                'model_type' => User::class,
                'model_id'   => $user->id,
            ]);
        }

        $this->command->info('✅ Grader users created:');
        $this->command->line('  GRD001 / Grader@001  — John Kamau (Kikuyu)');
        $this->command->line('  GRD002 / Grader@002  — Mary Wanjiku (Kiambu)');
        $this->command->line('  GRD003 / Grader@003  — Peter Odhiambo (Thika)');
    }
}
