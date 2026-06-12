<?php

namespace App\Modules\Hrm\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * HRM Module Seeder.
 *
 * Run with:
 *   php8.3 artisan db:seed --class="App\Modules\Hrm\Database\Seeders\HrmSeeder"
 *
 * Seeds only HRM permissions (guard: api) so the module owns its own
 * access control and can be installed independently of the core seeder.
 */
class HrmSeeder extends Seeder
{
    public function run(): void
    {
        app()['cache']->forget('spatie.permission.cache');

        $permissions = ['view-hrm', 'create-hrm', 'edit-hrm', 'delete-hrm'];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'api',
            ]);
        }

        // Grant the full HRM permission set to administrative roles, if present.
        foreach (['super_admin', 'admin'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'api')->first();
            $role?->givePermissionTo($permissions);
        }
    }
}
