<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Check Permission Middleware
 * 
 * Validates that the authenticated user has the required permission.
 * Usage: middleware('permission:view-users')
 */
class CheckPermission
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = auth()->user();

        if (!$user || !$user->hasPermissionTo($permission)) {
            return response()->json([
                'message' => 'Unauthorized: You do not have the required permission.',
                'required_permission' => $permission,
            ], 403);
        }

        return $next($request);
    }
}
