<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Check Role Middleware
 * 
 * Validates that the authenticated user has the required role.
 * Usage: middleware('role:admin')
 */
class CheckRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = auth()->user();

        if (!$user || !$user->hasRole($role)) {
            return response()->json([
                'message' => 'Unauthorized: You do not have the required role.',
                'required_role' => $role,
            ], 403);
        }

        return $next($request);
    }
}
