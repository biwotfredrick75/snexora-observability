<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\SalesArea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesAreaController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(SalesArea::orderBy('area_name')->get(), 'Sales areas retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['area_name' => 'required|string|max:100']);
        return ApiResponse::created(SalesArea::create($data), 'Sales area added');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $area = SalesArea::findOrFail($id);
        $data = $request->validate(['area_name' => 'required|string|max:100']);
        $area->update($data);
        return ApiResponse::updated($area, 'Sales area updated');
    }

    public function destroy(int $id): JsonResponse
    {
        SalesArea::findOrFail($id)->delete();
        return ApiResponse::deleted('Sales area deleted');
    }
}
