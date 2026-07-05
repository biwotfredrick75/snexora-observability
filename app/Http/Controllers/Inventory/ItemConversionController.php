<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Item;
use App\Models\ItemConversionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemConversionController extends Controller
{
    public function index(string $stockId): JsonResponse
    {
        Item::findOrFail($stockId);
        $items = ItemConversionItem::where("stock_id", $stockId)->get();
        return ApiResponse::success($items, 'Conversion items retrieved');
    }

    public function store(Request $request, string $stockId): JsonResponse
    {
        Item::findOrFail($stockId);
        $validated = $request->validate([
            'component_id'      => 'required|string|exists:items,stock_id',
            'quantity_produced' => 'required|numeric|min:0',
        ]);
        $validated['stock_id'] = $stockId;
        $conv = ItemConversionItem::create($validated);
        return ApiResponse::created($conv, 'Conversion item added');
    }

    public function destroy(string $stockId, int $id): JsonResponse
    {
        $conv = ItemConversionItem::where("stock_id", $stockId)->findOrFail($id);
        $conv->delete();
        return ApiResponse::deleted('Conversion item removed');
    }
}
