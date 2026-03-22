<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create roles
        $adminRole = Role::firstOrCreate(
            ['name' => 'admin', 'guard_name' => 'web'],
            ['display_name' => 'Administrator', 'description' => 'Full system access']
        );

        $managerRole = Role::firstOrCreate(
            ['name' => 'manager', 'guard_name' => 'web'],
            ['display_name' => 'Manager', 'description' => 'Department manager with limited access']
        );

        $supervisorRole = Role::firstOrCreate(
            ['name' => 'supervisor', 'guard_name' => 'web'],
            ['display_name' => 'Supervisor', 'description' => 'Team supervisor']
        );

        $userRole = Role::firstOrCreate(
            ['name' => 'user', 'guard_name' => 'web'],
            ['display_name' => 'User', 'description' => 'Regular system user']
        );

        $viewerRole = Role::firstOrCreate(
            ['name' => 'viewer', 'guard_name' => 'web'],
            ['display_name' => 'Viewer', 'description' => 'Read-only access']
        );

        $guestRole = Role::firstOrCreate(
            ['name' => 'guest', 'guard_name' => 'web'],
            ['display_name' => 'Guest', 'description' => 'Limited guest access']
        );

        $this->command->info('✅ Roles created successfully');
    }
}
