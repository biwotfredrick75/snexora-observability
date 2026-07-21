<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $superAdminRole = Role::firstOrCreate(
            ['name' => 'super_admin', 'guard_name' => 'api'],
            ['display_name' => 'Super Administrator', 'description' => 'Unrestricted system access']
        );

        $adminRole = Role::firstOrCreate(
            ['name' => 'admin', 'guard_name' => 'api'],
            ['display_name' => 'Administrator', 'description' => 'Full system access']
        );

        $managerRole = Role::firstOrCreate(
            ['name' => 'manager', 'guard_name' => 'api'],
            ['display_name' => 'Manager', 'description' => 'Department manager with limited access']
        );

        $supervisorRole = Role::firstOrCreate(
            ['name' => 'supervisor', 'guard_name' => 'api'],
            ['display_name' => 'Supervisor', 'description' => 'Team supervisor']
        );

        $userRole = Role::firstOrCreate(
            ['name' => 'user', 'guard_name' => 'api'],
            ['display_name' => 'User', 'description' => 'Regular system user']
        );

        $salesRole = Role::firstOrCreate(
            ['name' => 'sales', 'guard_name' => 'api'],
            ['display_name' => 'Sales', 'description' => 'Sales team member with sales-module access']
        );

        $viewerRole = Role::firstOrCreate(
            ['name' => 'viewer', 'guard_name' => 'api'],
            ['display_name' => 'Viewer', 'description' => 'Read-only access']
        );

        $guestRole = Role::firstOrCreate(
            ['name' => 'guest', 'guard_name' => 'api'],
            ['display_name' => 'Guest', 'description' => 'Limited guest access']
        );

        // Super admin gets everything, always — run this last so it
        // picks up any permissions created earlier in this seeder run
        $superAdminRole->syncPermissions(Permission::all());

        $this->command->info('✅ Roles created successfully');
    }
}