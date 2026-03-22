<?php

namespace App\Services\Auth;

use App\Repositories\Auth\UserRepository;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Role and Permission Service
 * 
 * Manages role and permission assignment, revocation,
 * and permission checking for users.
 */
class RolePermissionService
{
    /**
     * Create a new service instance.
     */
    public function __construct(
        protected UserRepository $userRepository
    ) {}

    /**
     * Create a new role.
     */
    public function createRole(string $name, ?string $displayName = null, ?string $description = null): Role
    {
        return Role::create([
            'name' => $name,
            'display_name' => $displayName ?? $name,
            'description' => $description,
            'guard_name' => 'web',
        ]);
    }

    /**
     * Create a new permission.
     */
    public function createPermission(string $name, ?string $displayName = null, ?string $description = null): Permission
    {
        return Permission::create([
            'name' => $name,
            'display_name' => $displayName ?? $name,
            'description' => $description,
            'guard_name' => 'web',
        ]);
    }

    /**
     * Give permission to role.
     */
    public function givePermissionToRole(string $roleName, string $permissionName): bool
    {
        $role = Role::where('name', $roleName)->first();
        $permission = Permission::where('name', $permissionName)->first();

        if (!$role || !$permission) {
            return false;
        }

        $role->givePermissionTo($permission);
        return true;
    }

    /**
     * Revoke permission from role.
     */
    public function revokePermissionFromRole(string $roleName, string $permissionName): bool
    {
        $role = Role::where('name', $roleName)->first();
        $permission = Permission::where('name', $permissionName)->first();

        if (!$role || !$permission) {
            return false;
        }

        $role->revokePermissionTo($permission);
        return true;
    }

    /**
     * Get all roles.
     */
    public function getAllRoles()
    {
        return Role::all();
    }

    /**
     * Get all permissions.
     */
    public function getAllPermissions()
    {
        return Permission::all();
    }

    /**
     * Get role by name with permissions.
     */
    public function getRoleWithPermissions(string $roleName)
    {
        return Role::where('name', $roleName)
            ->with('permissions')
            ->first();
    }

    /**
     * Delete role.
     */
    public function deleteRole(string $roleName): bool
    {
        $role = Role::where('name', $roleName)->first();

        if (!$role) {
            return false;
        }

        $role->delete();
        return true;
    }

    /**
     * Delete permission.
     */
    public function deletePermission(string $permissionName): bool
    {
        $permission = Permission::where('name', $permissionName)->first();

        if (!$permission) {
            return false;
        }

        $permission->delete();
        return true;
    }

    /**
     * Check if user has all permissions.
     */
    public function userHasAllPermissions(int $userId, array $permissions): bool
    {
        $user = $this->userRepository->find($userId);

        if (!$user) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (!$user->hasPermissionTo($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if user has any permission.
     */
    public function userHasAnyPermission(int $userId, array $permissions): bool
    {
        $user = $this->userRepository->find($userId);

        if (!$user) {
            return false;
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermissionTo($permission)) {
                return true;
            }
        }

        return false;
    }
}
