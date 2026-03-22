<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PassportAuthController extends Controller
{
    protected AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * OAuth2 Password Grant Token
     * 
     * External systems can obtain tokens using their credentials
     * POST /api/oauth/token
     * 
     * Required parameters:
     * - grant_type: "password"
     * - client_id: (from passport clients table)
     * - client_secret: (from passport clients table)
     * - username: user_id
     * - password: user password
     * - scope: (optional)
     * 
     * @return JsonResponse
     */
    public function issueToken(Request $request)
    {
        $request->request->add([
            'grant_type' => 'password',
            'client_id' => config('passport.client_id'),
            'client_secret' => config('passport.client_secret'),
            'username' => $request->input('user_id'),
            'password' => $request->input('password'),
            'scope' => $request->input('scope', '*'),
        ]);

        $tokenRequest = Request::create(
            '/oauth/token',
            'POST',
            $request->request->all()
        );

        return app()->handle($tokenRequest);
    }

    /**
     * Get authenticated user details
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();

            if (!$user) {
                return ApiResponse::unauthorized();
            }

            $userData = [
                'id' => $user->id,
                'user_id' => $user->user_id,
                'real_name' => $user->real_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role_id' => $user->role_id,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
                'inactive' => (bool) $user->inactive,
                'created_at' => $user->created_at,
            ];

            return ApiResponse::success($userData, 'User details retrieved');
        } catch (\Exception $e) {
            return ApiResponse::serverError($e->getMessage());
        }
    }

    /**
     * Revoke the current access token
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();
            if ($user) {
                // Revoke all tokens for the user
                $user->tokens()->update(['revoked' => true]);
            }

            return ApiResponse::deleted('Successfully logged out');
        } catch (\Exception $e) {
            return ApiResponse::serverError('Error logging out');
        }
    }

    /**
     * Refresh the access token using refresh token
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function refresh(Request $request)
    {
        $request->request->add([
            'grant_type' => 'refresh_token',
            'client_id' => config('passport.client_id'),
            'client_secret' => config('passport.client_secret'),
            'refresh_token' => $request->input('refresh_token'),
            'scope' => '*',
        ]);

        $tokenRequest = Request::create(
            '/oauth/token',
            'POST',
            $request->request->all()
        );

        return app()->handle($tokenRequest);
    }

    /**
     * List all active tokens for the authenticated user
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function tokens(Request $request): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $tokens = $user->tokens->map(function ($token) {
                return [
                    'id' => $token->id,
                    'name' => $token->name,
                    'scopes' => $token->scopes,
                    'revoked' => $token->revoked,
                    'created_at' => $token->created_at,
                    'expires_at' => $token->expires_at,
                ];
            })->toArray();

            return ApiResponse::collection($tokens, count($tokens), 'Tokens retrieved');
        } catch (\Exception $e) {
            return ApiResponse::serverError('Error retrieving tokens');
        }
    }

    /**
     * Revoke a specific token
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function revokeToken(Request $request): JsonResponse
    {
        try {
            $tokenId = $request->input('token_id');
            $user = auth('api')->user();
            
            $token = $user->tokens()->where('id', $tokenId)->first();
            
            if (!$token) {
                return ApiResponse::notFound('Token not found');
            }

            $token->update(['revoked' => true]);

            return ApiResponse::deleted('Token revoked successfully');
        } catch (\Exception $e) {
            return ApiResponse::serverError('Error revoking token');
        }
    }

    /**
     * Create a personal access token for integration
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function createPersonalToken(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'token_name' => 'required|string|max:100',
                'scopes' => 'array',
            ]);

            $user = auth('api')->user();
            $token = $user->createToken(
                $validated['token_name'],
                $validated['scopes'] ?? ['*']
            );

            return ApiResponse::created([
                'token_id' => $token->token->id,
                'access_token' => $token->accessToken,
                'token_type' => 'Bearer',
                'expires_at' => $token->token->expires_at,
            ], 'Personal access token created');
        } catch (\Exception $e) {
            return ApiResponse::serverError('Error creating token');
        }
    }
}
