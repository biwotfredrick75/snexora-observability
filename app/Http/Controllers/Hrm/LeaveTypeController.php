<?php

namespace App\Http\Controllers\Hrm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = LeaveType::orderBy('name');

        if (!$request->boolean('inactive')) {
            $query->where('inactive', false);
        }

        return ApiResponse::success($query->get(), 'Leave types retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'                   => 'required|string|max:30|unique:leave_types,code',
            'name'                   => 'required|string|max:100',
            'default_days_per_year'  => 'required|integer|min:0',
            'is_paid'                => 'boolean',
        ]);

        $type = LeaveType::create($data);

        return ApiResponse::created($type, 'Leave type created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $type = LeaveType::find($id);
        if (! $type) return ApiResponse::notFound('Leave type not found');

        $data = $request->validate([
            'code'                   => 'sometimes|string|max:30|unique:leave_types,code,' . $id,
            'name'                   => 'sometimes|string|max:100',
            'default_days_per_year'  => 'sometimes|integer|min:0',
            'is_paid'                => 'boolean',
            'inactive'               => 'boolean',
        ]);

        $type->update($data);

        return ApiResponse::updated($type->fresh(), 'Leave type updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $type = LeaveType::find($id);
        if (! $type) return ApiResponse::notFound('Leave type not found');

        if ($type->requests()->exists()) {
            $type->update(['inactive' => true]);
            return ApiResponse::deleted('Leave type has existing requests — deactivated instead of deleted');
        }

        $type->delete();

        return ApiResponse::deleted('Leave type deleted');
    }
}
