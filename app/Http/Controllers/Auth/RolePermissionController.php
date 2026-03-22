<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AssignRoleRequest;
use App\Http\Requests\Auth\CreateRoleRequest;
use App\Http\Requests\Auth\CreatePermissionRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\RolePermissionService;
use Illuminate\Http\JsonResponse;

/**
 * Role and Permission Controller
 * 
 * Handles role and permission management endpoints.
 */
class RolePermissionController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected RolePermissionService $rolePermissionService
    ) {}

    /**
     * Create a new role.
     */
    public function createRole(CreateRoleRequest $request): JsonResponse
    {
        $role = $this->rolePermissionService->createRole(
            $request->validated('name'),
            $request->validated('display_name'),
            $request->validated('description')
        );

        return ApiResponse::created($role, 'Role created successfully');
    }

    /**
     * Create a new permission.
     */
    public function createPermission(CreatePermissionRequest $request): JsonResponse
    {
        $permission = $this->rolePermissionService->createPermission(
            $request->validated('name'),
            $request->validated('display_name'),
            $request->validated('description')
        );

        return ApiResponse::created($permission, 'Permission created successfully');
    }

    /**
     * Assign permission to role.
     */
    public function assignPermissionToRole(AssignRoleRequest $request): JsonResponse
    {
        $success = $this->rolePermissionService->givePermissionToRole(
            $request->validated('role_name'),
            $request->validated('permission_name')
        );

        if (!$success) {
            return ApiResponse::notFound('Role or permission not found');
        }

        return ApiResponse::success(null, 'Permission assigned to role successfully');
    }

    /**
     * Get all roles.
     */
    public function getAllRoles(): JsonResponse
    {
        $roles = $this->rolePermissionService->getAllRoles()->toArray();

        return ApiResponse::collection($roles, count($roles), 'Roles retrieved successfully');
    }

    /**
     * Get all permissions.
     */
    public function getAllPermissions(): JsonResponse
    {
        $permissions = $this->rolePermissionService->getAllPermissions()->toArray();

        return ApiResponse::collection($permissions, count($permissions), 'Permissions retrieved successfully');
    }

    /**
     * Get role with permissions.
     */
    public function getRoleWithPermissions(string $roleName): JsonResponse
    {
        $role = $this->rolePermissionService->getRoleWithPermissions($roleName);

        if (!$role) {
            return ApiResponse::notFound('Role not found');
        }

        return ApiResponse::success($role, 'Role retrieved successfully');
    }

    /**
     * Delete role.
     */
    public function deleteRole(string $roleName): JsonResponse
    {
        $success = $this->rolePermissionService->deleteRole($roleName);

        if (!$success) {
            return ApiResponse::notFound('Role not found');
        }

        return ApiResponse::deleted('Role deleted successfully');
    }

    /**
     * Delete permission.
     */
    public function deletePermission(string $permissionName): JsonResponse
    {
        $success = $this->rolePermissionService->deletePermission($permissionName);

        if (!$success) {
            return ApiResponse::notFound('Permission not found');
        }

        return ApiResponse::deleted('Permission deleted successfully');
    }
}
