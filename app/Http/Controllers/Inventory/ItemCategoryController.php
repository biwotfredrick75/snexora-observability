<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ItemCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ItemCategory::orderBy('name');

        if (!$request->boolean('inactive')) {
            $query->where('inactive', false);
        }

        return ApiResponse::success($query->get(), 'Item categories retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                             => 'required|string|max:100|unique:item_categories,name',
            'description'                      => 'nullable|string',
            'item_tax_type'                    => 'nullable|string|max:50',
            'item_type'                        => 'nullable|in:Purchased,Manufactured,Service',
            'units_of_measure'                 => 'nullable|string|max:50',
            'exclude_from_sales'               => 'boolean',
            'exclude_from_purchases'           => 'boolean',
            'sales_gl_account'                 => 'nullable|string|max:50',
            'inventory_gl_account'             => 'nullable|string|max:50',
            'cogs_gl_account'                  => 'nullable|string|max:50',
            'inventory_adjustments_gl_account' => 'nullable|string|max:50',
            'item_assembly_costs_gl_account'   => 'nullable|string|max:50',
            'dimension1'                       => 'nullable|string|max:100',
            'dimension2'                       => 'nullable|string|max:100',
        ]);

        $category = ItemCategory::create($validated);

        return ApiResponse::created($category, 'Item category created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = ItemCategory::findOrFail($id);

        $validated = $request->validate([
            'name'                             => 'sometimes|string|max:100|unique:item_categories,name,' . $id,
            'description'                      => 'nullable|string',
            'item_tax_type'                    => 'nullable|string|max:50',
            'item_type'                        => 'nullable|in:Purchased,Manufactured,Service',
            'units_of_measure'                 => 'nullable|string|max:50',
            'exclude_from_sales'               => 'sometimes|boolean',
            'exclude_from_purchases'           => 'sometimes|boolean',
            'sales_gl_account'                 => 'nullable|string|max:50',
            'inventory_gl_account'             => 'nullable|string|max:50',
            'cogs_gl_account'                  => 'nullable|string|max:50',
            'inventory_adjustments_gl_account' => 'nullable|string|max:50',
            'item_assembly_costs_gl_account'   => 'nullable|string|max:50',
            'dimension1'                       => 'nullable|string|max:100',
            'dimension2'                       => 'nullable|string|max:100',
            'inactive'                         => 'sometimes|boolean',
        ]);

        $category->fill($validated)->save();

        return ApiResponse::updated($category->fresh(), 'Item category updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $category = ItemCategory::findOrFail($id);
        $category->update(['inactive' => true]);

        return ApiResponse::deleted('Item category deactivated');
    }
}
