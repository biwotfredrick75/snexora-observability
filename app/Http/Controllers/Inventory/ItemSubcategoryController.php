<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ItemSubcategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemSubcategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ItemSubcategory::orderBy('name');
        if (!$request->boolean('inactive')) {
            $query->where('inactive', false);
        }
        return ApiResponse::success($query->get(), 'Item subcategories retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:item_subcategories,name',
        ]);
        $sub = ItemSubcategory::create($validated);
        return ApiResponse::created($sub, 'Item subcategory created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $sub = ItemSubcategory::findOrFail($id);
        $validated = $request->validate([
            'name'     => 'sometimes|string|max:100|unique:item_subcategories,name,' . $id,
            'inactive' => 'sometimes|boolean',
        ]);
        $sub->fill($validated)->save();
        return ApiResponse::updated($sub->fresh(), 'Item subcategory updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $sub = ItemSubcategory::findOrFail($id);
        $sub->update(['inactive' => true]);
        return ApiResponse::deleted('Item subcategory deactivated');
    }
}
