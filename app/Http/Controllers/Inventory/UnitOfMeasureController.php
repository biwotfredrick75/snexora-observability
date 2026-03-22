<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\UnitOfMeasure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitOfMeasureController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = UnitOfMeasure::orderBy('unit');
        if (!$request->boolean('inactive')) {
            $query->where('inactive', false);
        }
        return ApiResponse::success($query->get(), 'Units of measure retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unit'           => 'required|string|max:20|unique:units_of_measure,unit',
            'description'    => 'nullable|string|max:100',
            'decimal_places' => 'nullable|string|max:30',
        ]);
        $uom = UnitOfMeasure::create($validated);
        return ApiResponse::created($uom, 'Unit of measure created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $uom = UnitOfMeasure::findOrFail($id);
        $validated = $request->validate([
            'unit'           => 'sometimes|string|max:20|unique:units_of_measure,unit,' . $id,
            'description'    => 'nullable|string|max:100',
            'decimal_places' => 'nullable|string|max:30',
            'inactive'       => 'sometimes|boolean',
        ]);
        $uom->fill($validated)->save();
        return ApiResponse::updated($uom->fresh(), 'Unit of measure updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $uom = UnitOfMeasure::findOrFail($id);
        $uom->update(['inactive' => true]);
        return ApiResponse::deleted('Unit of measure deactivated');
    }
}
