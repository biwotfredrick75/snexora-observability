<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\GlAccount;
use App\Models\GlAccountGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChartOfAccountsController extends Controller
{
    /** GET /api/banking/gl-accounts */
    public function index(Request $request): JsonResponse
    {
        $query = GlAccount::with('group');

        if (!$request->boolean('show_inactive')) {
            $query->where('inactive', false);
        }

        if ($request->filled('group_id')) {
            $query->where('group_id', $request->group_id);
        }

        $accounts = $query->orderBy('code')->get();

        return ApiResponse::success($accounts->toArray(), 'GL accounts retrieved');
    }

    /** POST /api/banking/gl-accounts */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'     => 'required|string|max:20|unique:gl_accounts,code',
            'code2'    => 'nullable|string|max:20',
            'name'     => 'required|string|max:150',
            'group_id' => 'nullable|exists:gl_account_groups,id',
            'tags'     => 'nullable|array',
            'inactive' => 'sometimes|boolean',
            'payment_provider' => 'nullable|string|in:mpesa,bank,cash,cheque',
            'mpesa_shortcode'  => 'nullable|string|max:20',
        ]);

        $account = GlAccount::create($validated);

        return ApiResponse::created($account->load('group'), 'Account created');
    }

    /** PUT /api/banking/gl-accounts/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $account = GlAccount::findOrFail($id);

        $validated = $request->validate([
            'code'     => 'sometimes|string|max:20|unique:gl_accounts,code,' . $id,
            'code2'    => 'nullable|string|max:20',
            'name'     => 'sometimes|string|max:150',
            'group_id' => 'nullable|exists:gl_account_groups,id',
            'tags'     => 'nullable|array',
            'inactive' => 'sometimes|boolean',
            'payment_provider' => 'nullable|string|in:mpesa,bank,cash,cheque',
            'mpesa_shortcode'  => 'nullable|string|max:20',
        ]);

        $account->update($validated);

        return ApiResponse::updated($account->load('group'), 'Account updated');
    }

    /** DELETE /api/banking/gl-accounts/{id} */
    public function destroy(int $id): JsonResponse
    {
        GlAccount::findOrFail($id)->delete();

        return ApiResponse::deleted('Account deleted');
    }

    /** GET /api/banking/gl-account-groups */
    public function groups(Request $request): JsonResponse
    {
        $query = GlAccountGroup::query();

        if (!$request->boolean('show_inactive')) {
            $query->where('inactive', false);
        }

        $groups = $query->orderBy('code')->get();

        return ApiResponse::success($groups->toArray(), 'Account groups retrieved');
    }

    /** POST /api/banking/gl-account-groups */
    public function storeGroup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'     => 'required|string|max:20|unique:gl_account_groups,code',
            'name'     => 'required|string|max:100',
            'inactive' => 'sometimes|boolean',
        ]);

        $group = GlAccountGroup::create($validated);

        return ApiResponse::created($group, 'Account group created');
    }
}
