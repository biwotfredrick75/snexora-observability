<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Dashboard permissions
        $this->createPermissions('Dashboard', [
            'dashboard.view' => 'View dashboard',
            'dashboard.export' => 'Export dashboard data',
        ]);

        // Users management
        $this->createPermissions('Users', [
            'users.view' => 'View users',
            'users.create' => 'Create users',
            'users.edit' => 'Edit users',
            'users.delete' => 'Delete users',
            'users.activate' => 'Activate/Deactivate users',
        ]);

        // Sales module
        $this->createPermissions('Sales', [
            'sales.view' => 'View sales',
            'sales.create' => 'Create sales orders',
            'sales.edit' => 'Edit sales orders',
            'sales.delete' => 'Delete sales orders',
            'sales.approve' => 'Approve sales orders',
            'sales.export' => 'Export sales data',
        ]);

        // Purchases module
        $this->createPermissions('Purchases', [
            'purchases.view' => 'View purchases',
            'purchases.create' => 'Create purchase orders',
            'purchases.edit' => 'Edit purchase orders',
            'purchases.delete' => 'Delete purchase orders',
            'purchases.approve' => 'Approve purchase orders',
            'purchases.export' => 'Export purchase data',
        ]);

        // Inventory management
        $this->createPermissions('Inventory', [
            'inventory.view' => 'View inventory',
            'inventory.adjust' => 'Adjust stock',
            'inventory.transfer' => 'Transfer stock',
            'inventory.export' => 'Export inventory data',
        ]);

        // Manufacturing
        $this->createPermissions('Manufacturing', [
            'manufacturing.view' => 'View manufacturing',
            'manufacturing.create' => 'Create work orders',
            'manufacturing.edit' => 'Edit work orders',
            'manufacturing.complete' => 'Complete production',
            'manufacturing.export' => 'Export manufacturing data',
        ]);

        // Farmers module
        $this->createPermissions('Farmers', [
            'farmers.view' => 'View farmers',
            'farmers.create' => 'Create farmer records',
            'farmers.edit' => 'Edit farmer records',
            'farmers.delete' => 'Delete farmer records',
            'farmers.export' => 'Export farmer data',
        ]);

        // Transport/Logistics
        $this->createPermissions('Transport', [
            'transport.view' => 'View transport',
            'transport.create' => 'Create shipments',
            'transport.edit' => 'Edit shipments',
            'transport.track' => 'Track shipments',
            'transport.export' => 'Export transport data',
        ]);

        // Assets management
        $this->createPermissions('Assets', [
            'assets.view' => 'View assets',
            'assets.create' => 'Create assets',
            'assets.edit' => 'Edit assets',
            'assets.depreciate' => 'Calculate depreciation',
            'assets.export' => 'Export assets data',
        ]);

        // Banking & Finance
        $this->createPermissions('Banking', [
            'banking.view' => 'View bank accounts',
            'banking.transaction' => 'Create transactions',
            'banking.reconcile' => 'Reconcile accounts',
            'banking.export' => 'Export banking data',
        ]);

        // Payroll
        $this->createPermissions('Payroll', [
            'payroll.view' => 'View payroll',
            'payroll.process' => 'Process salary',
            'payroll.approve' => 'Approve payroll',
            'payroll.export' => 'Export payroll data',
        ]);

        // Reports
        $this->createPermissions('Reports', [
            'reports.view' => 'View reports',
            'reports.create' => 'Create custom reports',
            'reports.export' => 'Export reports',
        ]);

        // System administration
        $this->createPermissions('System', [
            'system.settings' => 'Access system settings',
            'system.roles' => 'Manage roles',
            'system.permissions' => 'Manage permissions',
            'system.logs' => 'View system logs',
            'system.backup' => 'Manage backups',
        ]);

        // Assign permissions to roles
        $this->assignPermissionsToRoles();

        $this->command->info('✅ Permissions created and assigned successfully');
    }

    /**
     * Create permissions for a module.
     */
    private function createPermissions(string $module, array $permissions): void
    {
        foreach ($permissions as $name => $description) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'api'],
                ['description' => $description]
            );
        }
    }

    /**
     * Assign permissions to roles.
     */
    private function assignPermissionsToRoles(): void
    {
        $adminRole = Role::findByName('admin', 'api');
        $managerRole = Role::findByName('manager', 'api');
        $supervisorRole = Role::findByName('supervisor', 'api');
        $userRole = Role::findByName('user', 'api');
        $viewerRole = Role::findByName('viewer', 'api');
        $salesRole = Role::findByName('sales', 'api');

        // Admin gets all permissions
        $adminRole->syncPermissions(Permission::all());

        // Manager gets most permissions except system
        $managerPermissions = Permission::whereNotIn('name', [
            'system.settings',
            'system.roles',
            'system.permissions',
            'system.logs',
            'system.backup',
            'users.delete',
            'sales.delete',
            'purchases.delete',
            'farmers.delete',
        ])->get();
        $managerRole->syncPermissions($managerPermissions);

        // Supervisor can view most things and perform operations
        $supervisorPermissions = Permission::where(function ($q) {
            $q->where('name', 'like', '%.view')
              ->orWhere('name', 'like', '%.export')
              ->orWhere('name', 'like', '%.create')
              ->orWhere('name', 'like', '%.edit')
              ->orWhere('name', 'like', '%.approve');
        })->whereNotIn('name', [
            'system.settings',
            'system.roles',
            'system.permissions',
            'system.logs',
            'system.backup',
            'users.delete',
        ])->get();
        $supervisorRole->syncPermissions($supervisorPermissions);

        // User has basic access
        $userPermissions = Permission::where(function ($q) {
            $q->where('name', 'like', '%.view')
              ->orWhere('name', 'like', '%.export')
              ->orWhere('name', 'like', '%.create')
              ->orWhere('name', 'like', '%.edit');
        })->whereNotIn('name', [
            'system.%',
            'users.delete',
            'users.activate',
            'sales.delete',
            'purchases.delete',
            'farmers.delete',
            'inventory.transfer',
            'payroll.process',
            'payroll.approve',
        ])->get();
        $userRole->syncPermissions($userPermissions);

        // Viewer only has view and export
        $viewerPermissions = Permission::where(function ($q) {
            $q->where('name', 'like', '%.view')
              ->orWhere('name', 'like', '%.export');
        })->get();
        $viewerRole->syncPermissions($viewerPermissions);

        // Sales gets the sales module (minus delete) plus dashboard and reports
        $salesPermissions = Permission::where(function ($q) {
            $q->where('name', 'like', 'sales.%')
              ->orWhereIn('name', ['dashboard.view', 'reports.view', 'reports.export']);
        })->whereNotIn('name', ['sales.delete'])->get();
        $salesRole->syncPermissions($salesPermissions);
    }
}
