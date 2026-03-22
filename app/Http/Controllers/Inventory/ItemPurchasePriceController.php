<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Item;
use App\Models\ItemPurchasePrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemPurchasePriceController extends Controller
{
    public function index(string $stockId): JsonResponse
    {
        Item::findOrFail($stockId);
        $prices = ItemPurchasePrice::where("stock_id", $stockId)->orderBy('id')->get();
        return ApiResponse::success($prices, 'Purchase prices retrieved');
    }

    public function store(Request $request, string $stockId): JsonResponse
    {
        Item::findOrFail($stockId);

        $validated = $request->validate([
            'supplier'             => 'required|string|max:200',
            'price'                => 'required|numeric|min:0',
            'currency'             => 'nullable|string|max:50',
            'supplier_uom'         => 'nullable|string|max:50',
            'conversion_factor'    => 'nullable|numeric|min:0',
            'supplier_description' => 'nullable|string|max:200',
        ]);

        $price = ItemPurchasePrice::create(['stock_id' => $stockId, ...$validated]);
        return ApiResponse::created($price, 'Purchase price added');
    }

    public function update(Request $request, string $stockId, int $priceId): JsonResponse
    {
        $price = ItemPurchasePrice::where("stock_id", $stockId)->findOrFail($priceId);

        $validated = $request->validate([
            'supplier'             => 'sometimes|string|max:200',
            'price'                => 'sometimes|numeric|min:0',
            'currency'             => 'nullable|string|max:50',
            'supplier_uom'         => 'nullable|string|max:50',
            'conversion_factor'    => 'nullable|numeric|min:0',
            'supplier_description' => 'nullable|string|max:200',
        ]);

        $price->fill($validated)->save();
        return ApiResponse::updated($price->fresh(), 'Purchase price updated');
    }

    public function destroy(string $stockId, int $priceId): JsonResponse
    {
        $price = ItemPurchasePrice::where("stock_id", $stockId)->findOrFail($priceId);
        $price->delete();
        return ApiResponse::deleted('Purchase price removed');
    }
}
