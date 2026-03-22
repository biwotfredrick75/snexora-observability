<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * GET /api/setup/users
     * Returns all users with their Spatie roles.
     */
    public function index(): JsonResponse
    {
        $users = User::with('roles')
            ->orderBy('id')
            ->get()
            ->map(fn($u) => $this->formatUser($u));

        return ApiResponse::success($users, 'Users retrieved');
    }

    /**
     * POST /api/setup/users
     * Create a new user.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id'       => 'required|string|max:60|unique:users,user_id',
            'password'      => 'required|string|min:6',
            'real_name'     => 'required|string|max:100',
            'phone'         => 'nullable|string|max:30',
            'email'         => 'nullable|email|max:100|unique:users,email',
            'role'          => 'nullable|string|exists:roles,name',
            'language'      => 'nullable|string|max:20',
            'pos'           => 'nullable|integer',
            'print_profile' => 'nullable|string|max:30',
            'rep_popup'     => 'nullable|boolean',
            'default_store' => 'nullable|string|max:50',
        ]);

        $user = User::create([
            'user_id'       => $validated['user_id'],
            'password'      => Hash::make($validated['password']),
            'real_name'     => $validated['real_name'],
            'phone'         => $validated['phone'] ?? '',
            'email'         => $validated['email'] ?? null,
            'language'      => $validated['language'] ?? null,
            'pos'           => $validated['pos'] ?? 1,
            'print_profile' => $validated['print_profile'] ?? '',
            'rep_popup'     => $validated['rep_popup'] ?? true,
            'default_store' => $validated['default_store'] ?? '',
            'pin'           => '',
            'startup_tab'   => '',
            'inactive'      => 0,
        ]);

        if (!empty($validated['role'])) {
            $user->syncRoles([$validated['role']]);
        }

        return ApiResponse::created(
            $this->formatUser($user->fresh('roles')),
            'User created successfully'
        );
    }

    /**
     * PUT /api/setup/users/{id}
     * Update an existing user's details.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'real_name'     => 'sometimes|string|max:100',
            'phone'         => 'sometimes|nullable|string|max:30',
            'email'         => 'sometimes|nullable|email|max:100|unique:users,email,' . $id,
            'role'          => 'sometimes|nullable|string|exists:roles,name',
            'language'      => 'sometimes|nullable|string|max:20',
            'pos'           => 'sometimes|nullable|integer',
            'print_profile' => 'sometimes|nullable|string|max:30',
            'rep_popup'     => 'sometimes|nullable|boolean',
            'default_store' => 'sometimes|nullable|string|max:50',
            'inactive'      => 'sometimes|boolean',
            'password'      => 'sometimes|nullable|string|min:6',
        ]);

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $role = $validated['role'] ?? null;
        unset($validated['role']);

        // phone, default_store, print_profile are NOT NULL — map null → ''
        foreach (['phone', 'default_store', 'print_profile', 'language'] as $col) {
            if (array_key_exists($col, $validated) && is_null($validated[$col])) {
                $validated[$col] = '';
            }
        }

        $user->fill($validated)->save();

        if ($role !== null) {
            $user->syncRoles([$role]);
        }

        return ApiResponse::updated(
            $this->formatUser($user->fresh('roles')),
            'User updated successfully'
        );
    }

    /**
     * DELETE /api/setup/users/{id}
     * Deactivates a user (sets inactive = 1).
     */
    public function destroy(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->update(['inactive' => true]);

        return ApiResponse::deleted('User deactivated successfully');
    }

    private function formatUser(User $user): array
    {
        return [
            'id'            => $user->id,
            'user_id'       => $user->user_id,
            'real_name'     => $user->real_name,
            'phone'         => $user->phone ?? '',
            'email'         => $user->email ?? '',
            'role'          => $user->roles->first()?->name ?? '',
            'language'      => $user->language ?? '',
            'pos'           => $user->pos,
            'print_profile' => $user->print_profile ?? '',
            'rep_popup'     => (bool) $user->rep_popup,
            'default_store' => $user->default_store ?? '',
            'inactive'      => (bool) $user->inactive,
            'last_visit_date' => $user->last_visit_date?->format('d/m/Y'),
            'joined'        => $user->created_at?->format('d/m/Y'),
        ];
    }
}
