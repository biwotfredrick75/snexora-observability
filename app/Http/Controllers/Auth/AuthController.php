<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Responses\ApiResponse;
use App\Models\ActivityLog;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;

/**
 * Authentication Controller
 * 
 * Handles user login, registration, and token management.
 */
class AuthController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected AuthService $authService
    ) {}

    /**
     * User login.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->authService->authenticateByEmail(
            $request->validated('email'),
            $request->validated('password')
        );

        if (!$user) {
            ActivityLog::record('login_failed', "Failed login attempt for {$request->validated('email')}");
            return ApiResponse::unauthorized('Invalid credentials or account is inactive.');
        }

        $token = $user->createToken('API Token')->accessToken;

        ActivityLog::record('login', "{$user->real_name} logged in", actor: $user);

        return ApiResponse::success([
            'user' => [
                'id'            => $user->id,
                'user_id'       => $user->user_id,
                'name'          => $user->real_name,
                'email'         => $user->email,
                'default_store' => $user->default_store,
                'permissions'   => $user->getAllPermissions()->pluck('name'),
                'roles'         => $user->getRoleNames(),
            ],
            'token'  => $token,
            'status' => 200,
        ], 'Login successful');
    }

    /**
     * User registration.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->authService->register($request->validated());

        return ApiResponse::created([
            'user' => [
                'id'      => $user->id,
                'user_id' => $user->user_id,
                'name'    => $user->real_name,
                'email'   => $user->email,
            ],
        ], 'User registered successfully');
    }

    /**
     * User logout.
     */
    public function logout(): JsonResponse
    {
        $user = auth()->user();
        if ($user) {
            // Revoke all tokens for the user
            $user->tokens()->update(['revoked' => true]);
            ActivityLog::record('logout', "{$user->real_name} logged out", actor: $user);
        }

        return ApiResponse::deleted('Logged out successfully');
    }

    /**
     * Get current user.
     */
    public function currentUser(): JsonResponse
    {
        $user = auth()->user();

        if (!$user) {
            return ApiResponse::unauthorized('Unauthenticated');
        }

        return ApiResponse::success([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name'),
            'permissions' => $user->getPermissionsViaRoles()->pluck('name'),
        ]);
    }
}
