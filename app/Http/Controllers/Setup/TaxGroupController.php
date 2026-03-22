<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\TaxGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TaxGroup::with(['taxTypes']);
        if (!$request->boolean('inactive')) {
            $query->where('inactive', false);
        }
        return ApiResponse::success($query->get(), 'Tax groups retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'description'           => 'required|string|max:100',
            'tax_types'             => 'nullable|array',
            'tax_types.*.id'        => 'required|exists:tax_types,id',
            'tax_types.*.shipping'  => 'boolean',
        ]);

        $group = TaxGroup::create(['description' => $data['description']]);
        $this->syncTaxTypes($group, $data['tax_types'] ?? []);

        return ApiResponse::created($group->load('taxTypes'), 'Tax group created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $group = TaxGroup::findOrFail($id);
        $data = $request->validate([
            'description'           => 'sometimes|string|max:100',
            'inactive'              => 'sometimes|boolean',
            'tax_types'             => 'nullable|array',
            'tax_types.*.id'        => 'required|exists:tax_types,id',
            'tax_types.*.shipping'  => 'boolean',
        ]);

        $group->fill(array_filter($data, fn($k) => in_array($k, ['description','inactive']), ARRAY_FILTER_USE_KEY))->save();

        if (array_key_exists('tax_types', $data)) {
            $this->syncTaxTypes($group, $data['tax_types'] ?? []);
        }

        return ApiResponse::updated($group->fresh()->load('taxTypes'), 'Tax group updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $group = TaxGroup::findOrFail($id);
        $group->update(['inactive' => true]);
        return ApiResponse::deleted('Tax group deactivated');
    }

    private function syncTaxTypes(TaxGroup $group, array $taxTypes): void
    {
        $sync = [];
        foreach ($taxTypes as $t) {
            $sync[$t['id']] = ['shipping' => $t['shipping'] ?? false];
        }
        $group->taxTypes()->sync($sync);
    }
}
