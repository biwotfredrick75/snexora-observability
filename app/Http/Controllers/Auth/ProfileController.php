<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    /**
     * Update the authenticated user's basic profile info.
     * PUT /api/oauth/profile
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'real_name' => 'required|string|max:100',
            'email'     => 'nullable|email|max:100',
            'phone'     => 'nullable|string|max:20',
        ]);

        $user = auth('api')->user();
        $user->update($validated);

        return ApiResponse::updated([
            'id'        => $user->id,
            'user_id'   => $user->user_id,
            'real_name' => $user->real_name,
            'email'     => $user->email,
            'phone'     => $user->phone,
        ], 'Profile updated successfully');
    }

    /**
     * Change the authenticated user's password.
     * PUT /api/oauth/password
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|confirmed',
        ]);

        $user = auth('api')->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return ApiResponse::validationError(
                ['current_password' => ['Current password is incorrect']]
            );
        }

        $user->update([
            'password'              => $validated['new_password'],
            'password_last_changed' => now(),
        ]);

        return ApiResponse::updated(null, 'Password changed successfully');
    }

    /**
     * Update the authenticated user's display/app preferences.
     * PUT /api/oauth/preferences
     */
    public function preferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_format'  => 'nullable|integer|in:0,1,2',
            'page_size'    => 'nullable|integer|in:10,20,50,100',
            'show_gl'      => 'nullable|boolean',
            'show_codes'   => 'nullable|boolean',
            'show_hints'   => 'nullable|boolean',
            'language'     => 'nullable|string|max:10',
        ]);

        $user = auth('api')->user();
        $user->update($validated);

        return ApiResponse::updated(null, 'Preferences saved');
    }
}
