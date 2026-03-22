<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ItemTaxType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemTaxTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ItemTaxType::with('exemptTypes');
        if (!$request->boolean('inactive')) {
            $query->where('inactive', false);
        }
        $items = $query->get()->map(function ($item) {
            return array_merge($item->toArray(), [
                'exempt_types' => $item->exemptTypes->pluck('id')->toArray(),
            ]);
        });
        return ApiResponse::success($items, 'Item tax types retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'description'  => 'required|string|max:100',
            'fully_exempt' => 'boolean',
            'exempt_types' => 'nullable|array',
            'exempt_types.*' => 'exists:tax_types,id',
        ]);

        $item = ItemTaxType::create([
            'description'  => $data['description'],
            'fully_exempt' => $data['fully_exempt'] ?? false,
        ]);
        $item->exemptTypes()->sync($data['exempt_types'] ?? []);

        return ApiResponse::created(
            array_merge($item->toArray(), ['exempt_types' => $data['exempt_types'] ?? []]),
            'Item tax type created'
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $item = ItemTaxType::findOrFail($id);
        $data = $request->validate([
            'description'    => 'sometimes|string|max:100',
            'fully_exempt'   => 'sometimes|boolean',
            'inactive'       => 'sometimes|boolean',
            'exempt_types'   => 'nullable|array',
            'exempt_types.*' => 'exists:tax_types,id',
        ]);

        $item->fill(array_filter($data, fn($k) => in_array($k, ['description','fully_exempt','inactive']), ARRAY_FILTER_USE_KEY))->save();

        if (array_key_exists('exempt_types', $data)) {
            $item->exemptTypes()->sync($data['exempt_types'] ?? []);
        }

        return ApiResponse::updated(
            array_merge($item->fresh()->toArray(), ['exempt_types' => $data['exempt_types'] ?? $item->exemptTypes->pluck('id')->toArray()]),
            'Item tax type updated'
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $item = ItemTaxType::findOrFail($id);
        $item->update(['inactive' => true]);
        return ApiResponse::deleted('Item tax type deactivated');
    }
}
