<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * GET /api/setup/roles
     * Returns all roles (with their assigned permission names).
     */
    public function index(): JsonResponse
    {
        $roles = Role::where('guard_name', 'api')
            ->with('permissions')
            ->orderBy('name')
            ->get()
            ->map(fn($r) => [
                'name'        => $r->name,
                'permissions' => $r->permissions->pluck('name')->toArray(),
            ]);

        return ApiResponse::success($roles, 'Roles retrieved');
    }

    /**
     * GET /api/setup/permissions
     * Returns all permission names.
     */
    public function allPermissions(): JsonResponse
    {
        $permissions = Permission::where('guard_name', 'api')
            ->orderBy('name')
            ->pluck('name');

        return ApiResponse::success($permissions, 'Permissions retrieved');
    }

    /**
     * POST /api/setup/roles
     * Create a new role.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:roles,name',
        ]);

        $role = Role::create([
            'name'       => $validated['name'],
            'guard_name' => 'api',
        ]);

        return ApiResponse::created(
            ['name' => $role->name, 'permissions' => []],
            'Role created successfully'
        );
    }

    /**
     * POST /api/setup/roles/{name}/sync
     * Replace all permissions on a role with the provided list.
     */
    public function sync(Request $request, string $name): JsonResponse
    {
        $role = Role::findByName($name, 'api');
        if (!$role) return ApiResponse::notFound('Role not found');

        $permissions = $request->input('permissions', []);
        $role->syncPermissions($permissions);

        // Flush Spatie permission cache
        app()['cache']->forget('spatie.permission.cache');

        return ApiResponse::success(
            [
                'name'        => $role->name,
                'permissions' => $role->fresh('permissions')->permissions->pluck('name')->toArray(),
            ],
            'Permissions updated successfully'
        );
    }

    /**
     * DELETE /api/setup/roles/{name}
     * Delete a role.
     */
    public function destroy(string $name): JsonResponse
    {
        $role = Role::findByName($name, 'api');
        if (!$role) return ApiResponse::notFound('Role not found');

        $role->delete();

        return ApiResponse::deleted('Role deleted successfully');
    }
}
