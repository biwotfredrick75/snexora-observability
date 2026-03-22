<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PackagingType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackagingTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PackagingType::orderBy('code');
        if (!$request->boolean('inactive')) {
            $query->where('inactive', false);
        }
        return ApiResponse::success($query->get(), 'Packaging types retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'        => 'required|string|max:30|unique:packaging_types,code',
            'name'        => 'required|string|max:100',
            'category'    => 'nullable|string|max:50',
            'capacity'    => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
        ]);
        $type = PackagingType::create($validated);
        return ApiResponse::created($type, 'Packaging type created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $type = PackagingType::findOrFail($id);
        $validated = $request->validate([
            'code'        => 'sometimes|string|max:30|unique:packaging_types,code,' . $id,
            'name'        => 'sometimes|string|max:100',
            'category'    => 'nullable|string|max:50',
            'capacity'    => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'inactive'    => 'sometimes|boolean',
        ]);
        $type->fill($validated)->save();
        return ApiResponse::updated($type->fresh(), 'Packaging type updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $type = PackagingType::findOrFail($id);
        $type->update(['inactive' => true]);
        return ApiResponse::deleted('Packaging type deactivated');
    }
}
