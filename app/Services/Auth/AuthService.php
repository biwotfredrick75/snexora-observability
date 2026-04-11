<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Repositories\Auth\UserRepository;
use Illuminate\Support\Facades\Hash;

/**
 * Authentication Service
 * 
 * Handles authentication logic including login, registration,
 * and credential verification.
 */
class AuthService
{
    /**
     * Create a new service instance.
     */
    public function __construct(
        protected UserRepository $userRepository
    ) {}

    /**
     * Authenticate user credentials by email (web login).
     */
    public function authenticateByEmail(string $email, string $password): ?User
    {
        $user = $this->userRepository->findByEmail($email);

        if (!$user || !Hash::check($password, $user->password)) {
            return null;
        }

        return $user;
    }

    /**
     * Authenticate user credentials by loc_code (Flutter/grader login).
     */
    public function authenticate(string $locCode, string $password): ?User
    {
        $user = $this->userRepository->findByLocCode($locCode);

        if (!$user || !Hash::check($password, $user->password)) {
            return null;
        }

        return $user;
    }

    /**
     * Register a new user.
     */
    public function register(array $data): User
    {
        $data['real_name'] = $data['name'];
        unset($data['name']);

        // Generate user_id from email prefix if not provided
        if (empty($data['user_id'])) {
            $data['user_id'] = strtolower(explode('@', $data['email'])[0]);
        }

        $data['password'] = Hash::make($data['password']);

        // Required fields with sensible defaults
        $data['pin']            = $data['pin'] ?? '';
        $data['phone']          = $data['phone'] ?? '';
        $data['print_profile']  = $data['print_profile'] ?? '';
        $data['startup_tab']    = $data['startup_tab'] ?? '';
        $data['default_store']  = $data['default_store'] ?? '';

        return $this->userRepository->create($data);
    }

    /**
     * Assign role to user.
     */
    public function assignRole(int $userId, string $roleName): bool
    {
        $user = $this->userRepository->find($userId);

        if (!$user) {
            return false;
        }

        $user->assignRole($roleName);
        return true;
    }

    /**
     * Revoke role from user.
     */
    public function revokeRole(int $userId, string $roleName): bool
    {
        $user = $this->userRepository->find($userId);

        if (!$user) {
            return false;
        }

        $user->removeRole($roleName);
        return true;
    }

    /**
     * Check if user has permission.
     */
    public function hasPermission(int $userId, string $permission): bool
    {
        $user = $this->userRepository->find($userId);

        if (!$user) {
            return false;
        }

        return $user->hasPermissionTo($permission);
    }

    /**
     * Check if user has role.
     */
    public function hasRole(int $userId, string $role): bool
    {
        $user = $this->userRepository->find($userId);

        if (!$user) {
            return false;
        }

        return $user->hasRole($role);
    }

    /**
     * Get user permissions.
     */
    public function getUserPermissions(int $userId): array
    {
        $user = $this->userRepository->find($userId);

        if (!$user) {
            return [];
        }

        return $user->getPermissionsViaRoles()->pluck('name')->toArray();
    }

    /**
     * Get user roles.
     */
    public function getUserRoles(int $userId): array
    {
        $user = $this->userRepository->find($userId);

        if (!$user) {
            return [];
        }

        return $user->roles->pluck('name')->toArray();
    }
}
