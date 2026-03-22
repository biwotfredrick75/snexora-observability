<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Item;
use App\Models\ItemSalesPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemSalesPriceController extends Controller
{
    public function index(string $stockId): JsonResponse
    {
        Item::findOrFail($stockId);
        $prices = ItemSalesPrice::where("stock_id", $stockId)->orderBy('id')->get();
        return ApiResponse::success($prices, 'Sales prices retrieved');
    }

    public function store(Request $request, string $stockId): JsonResponse
    {
        Item::findOrFail($stockId);

        $validated = $request->validate([
            'currency'   => 'required|string|max:50',
            'sales_type' => 'required|string|max:100',
            'price'      => 'required|numeric|min:0',
        ]);

        $price = ItemSalesPrice::create(['stock_id' => $stockId, ...$validated]);
        return ApiResponse::created($price, 'Sales price added');
    }

    public function update(Request $request, string $stockId, int $priceId): JsonResponse
    {
        $price = ItemSalesPrice::where("stock_id", $stockId)->findOrFail($priceId);

        $validated = $request->validate([
            'currency'   => 'required|string|max:50',
            'sales_type' => 'required|string|max:100',
            'price'      => 'required|numeric|min:0',
        ]);

        $price->update($validated);
        return ApiResponse::updated($price, 'Sales price updated');
    }

    public function destroy(string $stockId, int $priceId): JsonResponse
    {
        $price = ItemSalesPrice::where("stock_id", $stockId)->findOrFail($priceId);
        $price->delete();
        return ApiResponse::deleted('Sales price removed');
    }
}
