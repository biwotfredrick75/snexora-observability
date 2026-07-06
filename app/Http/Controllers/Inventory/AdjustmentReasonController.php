<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AdjustmentReason;
use App\Models\InventoryAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdjustmentReasonController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AdjustmentReason::orderBy('label');

        if (!$request->boolean('inactive')) {
            $query->where('inactive', false);
        }

        return ApiResponse::success($query->get(), 'Adjustment reasons retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'       => 'required|string|max:30|unique:adjustment_reasons,code',
            'label'      => 'required|string|max:60',
            'gl_account' => 'nullable|string|max:15|exists:gl_accounts,code',
        ]);

        $reason = AdjustmentReason::create($validated);

        return ApiResponse::created($reason, 'Adjustment reason created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $reason = AdjustmentReason::findOrFail($id);

        $validated = $request->validate([
            'code'       => 'sometimes|string|max:30|unique:adjustment_reasons,code,' . $id,
            'label'      => 'sometimes|string|max:60',
            'gl_account' => 'nullable|string|max:15|exists:gl_accounts,code',
            'inactive'   => 'sometimes|boolean',
        ]);

        $reason->update($validated);

        return ApiResponse::updated($reason->fresh(), 'Adjustment reason updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $reason = AdjustmentReason::findOrFail($id);

        if (InventoryAdjustment::where('reason_id', $id)->exists()) {
            return ApiResponse::error('Reason is in use by existing adjustments and cannot be deleted', 422);
        }

        $reason->delete();

        return ApiResponse::deleted('Adjustment reason deleted');
    }
}
