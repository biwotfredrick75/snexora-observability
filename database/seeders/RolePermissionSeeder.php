<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Role and Permission Seeder
 * 
 * Seeds the database with default roles and permissions.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()['cache']->forget('spatie.permission.cache');

        // Define permissions
        $permissions = [
            // User Management
            'view-users',
            'create-users',
            'edit-users',
            'delete-users',

            // Role Management
            'view-roles',
            'create-roles',
            'edit-roles',
            'delete-roles',

            // Permission Management
            'view-permissions',
            'create-permissions',
            'edit-permissions',
            'delete-permissions',

            // Dashboard
            'view-dashboard',

            // Sales Module
            'view-sales',
            'create-sales',
            'edit-sales',
            'delete-sales',

            // Purchases Module
            'view-purchases',
            'create-purchases',
            'edit-purchases',
            'delete-purchases',

            // Inventory Module
            'view-inventory',
            'manage-inventory',

            // Farmers Module
            'view-farmers',
            'manage-farmers',

            // Assets Module
            'view-assets',
            'manage-assets',

            // Banking Module
            'view-banking',
            'manage-banking',

            // Manufacturing Module
            'view-manufacturing',
            'manage-manufacturing',
            'reports-manufacturing',

            // Transport Module
            'view-transport',
            'manage-transport',

            // Payroll Module
            'view-payroll',
            'manage-payroll',

            // HRM Module
            'view-hrm',
            'manage-hrm',

            // CRM Module
            'view-crm',
            'manage-crm',

            // Setup Module
            'view-setup',
            'manage-setup',

            // Role Assignment
            'assign-roles',
        ];

        // Create permissions
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        // Define roles with their permissions
        $roles = [
            'super_admin' => $permissions, // Super admin has all permissions

            'admin' => [
                'view-users',
                'create-users',
                'edit-users',
                'view-roles',
                'view-permissions',
                'view-dashboard',
                'view-sales',
                'view-purchases',
                'view-inventory',
                'view-farmers',
                'view-assets',
                'view-banking',
                'view-manufacturing',
                'manage-manufacturing',
                'reports-manufacturing',
                'view-transport',
                'view-payroll',
                'view-hrm',
                'view-crm',
                'view-setup',
            ],

            'sales_manager' => [
                'view-dashboard',
                'view-sales',
                'create-sales',
                'edit-sales',
                'view-farmers',
                'view-inventory',
                'view-crm',
            ],

            'purchase_manager' => [
                'view-dashboard',
                'view-purchases',
                'create-purchases',
                'edit-purchases',
                'view-farmers',
                'view-inventory',
                'view-manufacturing',
            ],

            'inventory_manager' => [
                'view-dashboard',
                'view-inventory',
                'manage-inventory',
                'view-manufacturing',
                'manage-manufacturing',
                'reports-manufacturing',
            ],

            'user' => [
                'view-dashboard',
            ],
        ];

        // Create roles and assign permissions
        foreach ($roles as $roleName => $rolePermissions) {
            $role = Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'api'],
                ['display_name' => ucfirst(str_replace('_', ' ', $roleName))]
            );

            // Sync permissions
            $permissionIds = Permission::whereIn('name', $rolePermissions)
                ->pluck('id')
                ->toArray();

            $role->syncPermissions($permissionIds);
        }
    }
}
