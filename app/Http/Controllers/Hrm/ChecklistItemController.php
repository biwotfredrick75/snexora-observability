<?php

namespace App\Http\Controllers\Hrm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\HrChecklistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChecklistItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => 'required|in:onboarding,offboarding']);

        $items = HrChecklistItem::where('type', $data['type'])
            ->where('inactive', false)
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success($items, 'Checklist items retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type'        => 'required|in:onboarding,offboarding',
            'title'       => 'required|string|max:150',
            'description' => 'nullable|string|max:255',
            'category'    => 'nullable|string|max:60',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $data['sort_order'] = $data['sort_order'] ?? (HrChecklistItem::where('type', $data['type'])->max('sort_order') + 1);

        $item = HrChecklistItem::create($data);

        return ApiResponse::created($item, 'Checklist item created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $item = HrChecklistItem::find($id);
        if (! $item) return ApiResponse::notFound('Checklist item not found');

        $data = $request->validate([
            'title'       => 'sometimes|string|max:150',
            'description' => 'nullable|string|max:255',
            'category'    => 'nullable|string|max:60',
            'sort_order'  => 'nullable|integer|min:0',
            'inactive'    => 'boolean',
        ]);

        $item->update($data);

        return ApiResponse::updated($item->fresh(), 'Checklist item updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $item = HrChecklistItem::find($id);
        if (! $item) return ApiResponse::notFound('Checklist item not found');

        $item->update(['inactive' => true]);

        return ApiResponse::deleted('Checklist item deactivated');
    }
}
