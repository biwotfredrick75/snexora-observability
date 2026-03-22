<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\MilkPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MilkPriceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MilkPrice::orderBy('price_type')->orderBy('from_qty');
        if ($request->filled('price_type')) {
            $query->where('price_type', $request->price_type);
        }
        return ApiResponse::success($query->get(), 'Milk prices retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'price_type' => 'required|in:normal,special,monthly',
            'from_qty'   => 'required|numeric|min:0',
            'to_qty'     => 'required|numeric|min:0',
            'price'      => 'required|numeric|min:0',
            'date_from'  => 'required|date',
            'date_to'    => 'required|date|after_or_equal:date_from',
        ]);
        $price = MilkPrice::create($data);
        return ApiResponse::created($price, 'Milk price created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $price = MilkPrice::findOrFail($id);
        $data  = $request->validate([
            'price_type' => 'sometimes|in:normal,special,monthly',
            'from_qty'   => 'sometimes|numeric|min:0',
            'to_qty'     => 'sometimes|numeric|min:0',
            'price'      => 'sometimes|numeric|min:0',
            'date_from'  => 'sometimes|date',
            'date_to'    => 'sometimes|date',
        ]);
        $price->fill($data)->save();
        return ApiResponse::updated($price->fresh(), 'Milk price updated');
    }

    public function destroy(int $id): JsonResponse
    {
        MilkPrice::findOrFail($id)->delete();
        return ApiResponse::deleted('Milk price deleted');
    }
}
