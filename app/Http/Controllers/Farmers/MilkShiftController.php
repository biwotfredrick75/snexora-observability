<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\MilkCollectionShift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MilkShiftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MilkCollectionShift::orderBy('description');
        if (!$request->boolean('show_inactive')) {
            $query->where('active', true);
        }
        return ApiResponse::success($query->get(), 'Shifts retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'description' => 'required|string|max:150',
            'start_time'  => 'required|string|max:10',
            'end_time'    => 'required|string|max:10',
            'active'      => 'sometimes|boolean',
        ]);
        $shift = MilkCollectionShift::create($data);
        return ApiResponse::created($shift, 'Shift created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $shift = MilkCollectionShift::findOrFail($id);
        $data  = $request->validate([
            'description' => 'sometimes|string|max:150',
            'start_time'  => 'sometimes|string|max:10',
            'end_time'    => 'sometimes|string|max:10',
            'active'      => 'sometimes|boolean',
        ]);
        $shift->fill($data)->save();
        return ApiResponse::updated($shift->fresh(), 'Shift updated');
    }

    public function destroy(int $id): JsonResponse
    {
        MilkCollectionShift::findOrFail($id)->delete();
        return ApiResponse::deleted('Shift deleted');
    }
}
