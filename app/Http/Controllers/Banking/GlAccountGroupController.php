<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\GlAccountGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlAccountGroupController extends Controller
{
    /** GET /api/banking/gl-groups */
    public function index(Request $request): JsonResponse
    {
        $query = GlAccountGroup::with(['class', 'parent'])->orderBy('code');

        if (!$request->boolean('show_inactive')) {
            $query->where('inactive', false);
        }

        return ApiResponse::success($query->get()->toArray(), 'GL groups retrieved');
    }

    /** POST /api/banking/gl-groups */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'      => 'required|string|max:20|unique:gl_account_groups,code',
            'name'      => 'required|string|max:100',
            'class_id'  => 'nullable|exists:gl_account_classes,id',
            'parent_id' => 'nullable|exists:gl_account_groups,id',
            'inactive'  => 'sometimes|boolean',
        ]);

        $group = GlAccountGroup::create($validated);

        return ApiResponse::created($group->load(['class', 'parent']), 'Group created');
    }

    /** PUT /api/banking/gl-groups/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        $group = GlAccountGroup::findOrFail($id);

        $validated = $request->validate([
            'code'      => 'sometimes|string|max:20|unique:gl_account_groups,code,' . $id,
            'name'      => 'sometimes|string|max:100',
            'class_id'  => 'nullable|exists:gl_account_classes,id',
            'parent_id' => 'nullable|exists:gl_account_groups,id',
            'inactive'  => 'sometimes|boolean',
        ]);

        $group->update($validated);

        return ApiResponse::updated($group->load(['class', 'parent']), 'Group updated');
    }

    /** DELETE /api/banking/gl-groups/{id} */
    public function destroy(int $id): JsonResponse
    {
        GlAccountGroup::findOrFail($id)->delete();

        return ApiResponse::deleted('Group deleted');
    }
}
