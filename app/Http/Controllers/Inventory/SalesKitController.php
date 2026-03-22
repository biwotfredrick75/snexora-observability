<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\SalesKit;
use App\Models\SalesKitItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesKitController extends Controller
{
    // Kits list
    public function index(Request $request): JsonResponse
    {
        $query = SalesKit::orderBy('code');
        if (!$request->boolean('inactive')) {
            $query->where('inactive', false);
        }
        return ApiResponse::success($query->get(), 'Sales kits retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:sales_kits,code',
            'name' => 'required|string|max:150',
        ]);
        $kit = SalesKit::create($validated);
        return ApiResponse::created($kit, 'Sales kit created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $kit = SalesKit::findOrFail($id);
        $validated = $request->validate([
            'code'     => 'sometimes|string|max:50|unique:sales_kits,code,' . $id,
            'name'     => 'sometimes|string|max:150',
            'inactive' => 'sometimes|boolean',
        ]);
        $kit->fill($validated)->save();
        return ApiResponse::updated($kit->fresh(), 'Sales kit updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $kit = SalesKit::findOrFail($id);
        $kit->update(['inactive' => true]);
        return ApiResponse::deleted('Sales kit deactivated');
    }

    // Kit Items (nested)
    public function kitItems(int $kitId): JsonResponse
    {
        $kit = SalesKit::findOrFail($kitId);
        return ApiResponse::success($kit->items()->get(), 'Kit items retrieved');
    }

    public function addKitItem(Request $request, int $kitId): JsonResponse
    {
        SalesKit::findOrFail($kitId);
        $validated = $request->validate([
            'alias_code'   => 'nullable|string|max:50',
            'component_id' => 'required|integer|exists:items,id',
            'quantity'     => 'required|numeric|min:0',
            'uom'          => 'nullable|string|max:20',
        ]);
        $validated['kit_id'] = $kitId;
        $item = SalesKitItem::create($validated);
        return ApiResponse::created($item, 'Kit item added');
    }

    public function removeKitItem(int $kitId, int $itemId): JsonResponse
    {
        $item = SalesKitItem::where('kit_id', $kitId)->findOrFail($itemId);
        $item->delete();
        return ApiResponse::deleted('Kit item removed');
    }
}
