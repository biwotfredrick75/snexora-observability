<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Farmer;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Flutter Grader Authentication Controller
 *
 * Handles login for field graders/milkmen using their loc_code.
 * Used exclusively by the Flutter mobile application.
 */
class GraderAuthController extends Controller
{
    public function __construct(
        protected AuthService $authService
    ) {}

    /**
     * Grader login via loc_code + password.
     *
     * POST /api/grader/login
     * Body: { loc_code, password }
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'loc_code' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = $this->authService->authenticate($data['loc_code'], $data['password']);

        if (!$user) {
            return ApiResponse::unauthorized('Invalid location code or password.');
        }

        $token = $user->createToken('Grader Token')->accessToken;

        // Load the route directly from the grader's assigned route_id
        $route = $user->route_id
            ? \App\Models\MilkRoute::find($user->route_id)
            : null;

        // Resolve inventory location via default_store (for grader_id on milk purchases)
        $location = $user->default_store
            ? \DB::table('inventory_locations')
                ->where('code', $user->default_store)
                ->first(['id', 'code', 'name'])
            : null;

        $farmers = [];
        if ($route) {
            $farmers = Farmer::where('route_id', $route->id)
                ->where('status', '!=', 'inactive')
                ->orderBy('full_name')
                ->limit(500)
                ->get(['id', 'farmer_no', 'full_name', 'short_name', 'phone', 'status']);
        }

        return ApiResponse::success([
            'token' => $token,
            'grader' => [
                'id'            => $user->id,
                'loc_code'      => $user->user_id,
                'location_id'   => $location?->id,
                'location_code' => $location?->code,
                'name'          => $user->real_name,
                'roles'         => $user->getRoleNames(),
            ],
            'route'   => $route,
            'farmers' => $farmers,
        ], 'Grader login successful');
    }

    /**
     * Grader logout.
     *
     * POST /api/grader/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            $user->tokens()->update(['revoked' => true]);
        }

        return ApiResponse::deleted('Logged out successfully');
    }
}
