<?php

namespace App\Http\Controllers\Manufacturing;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\WorkCentre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkCentreController extends Controller
{
    public function index(): JsonResponse
    {
        $centres = WorkCentre::orderBy('code')->get();
        return ApiResponse::success($centres, 'Work centres retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'           => 'required|string|max:30|unique:work_centres,code',
            'name'           => 'required|string|max:100',
            'description'    => 'nullable|string',
            'rate_per_hour'  => 'nullable|numeric|min:0',
            'overhead_rate'  => 'nullable|numeric|min:0',
            'gl_account'     => 'nullable|string|max:20',
            'inactive'       => 'boolean',
        ]);

        $data['rate_per_hour'] ??= 0;
        $data['overhead_rate'] ??= 0;

        $centre = WorkCentre::create($data);
        return ApiResponse::created($centre, 'Work centre created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $centre = WorkCentre::findOrFail($id);
        $data = $request->validate([
            'code'           => "required|string|max:30|unique:work_centres,code,{$id}",
            'name'           => 'required|string|max:100',
            'description'    => 'nullable|string',
            'rate_per_hour'  => 'nullable|numeric|min:0',
            'overhead_rate'  => 'nullable|numeric|min:0',
            'gl_account'     => 'nullable|string|max:20',
            'inactive'       => 'boolean',
        ]);

        $data['rate_per_hour'] ??= 0;
        $data['overhead_rate'] ??= 0;

        $centre->fill($data)->save();
        return ApiResponse::updated($centre->fresh(), 'Work centre updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $centre = WorkCentre::findOrFail($id);
        if ($centre->workOrders()->exists()) {
            return ApiResponse::error('Cannot delete — work orders reference this work centre.', 422);
        }
        $centre->delete();
        return ApiResponse::deleted('Work centre deleted');
    }
}
