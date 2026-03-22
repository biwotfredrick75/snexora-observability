<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ContactCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ContactCategory::orderBy('category_type')->orderBy('category_subtype');

        if (!$request->boolean('inactive')) {
            $query->where('inactive', false);
        }

        return ApiResponse::success($query->get(), 'Contact categories retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_type'    => 'required|string|max:50',
            'category_subtype' => 'required|string|max:50',
            'short_name'       => 'required|string|max:50',
            'description'      => 'nullable|string',
        ]);

        $category = ContactCategory::create($validated);

        return ApiResponse::created($category, 'Contact category created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = ContactCategory::findOrFail($id);

        $validated = $request->validate([
            'category_type'    => 'sometimes|string|max:50',
            'category_subtype' => 'sometimes|string|max:50',
            'short_name'       => 'sometimes|string|max:50',
            'description'      => 'nullable|string',
            'inactive'         => 'sometimes|boolean',
        ]);

        $category->fill($validated)->save();

        return ApiResponse::updated($category->fresh(), 'Contact category updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $category = ContactCategory::findOrFail($id);
        $category->update(['inactive' => true]);

        return ApiResponse::deleted('Contact category deactivated');
    }
}
