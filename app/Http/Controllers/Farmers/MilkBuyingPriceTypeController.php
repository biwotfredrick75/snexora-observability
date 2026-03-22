<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\MilkBuyingPriceType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MilkBuyingPriceTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MilkBuyingPriceType::orderBy('name');
        if (!$request->boolean('show_inactive')) {
            $query->where('active', true);
        }
        return ApiResponse::success($query->get(), 'Price types retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => 'required|string|max:150',
            'description' => 'nullable|string|max:255',
            'active'      => 'sometimes|boolean',
        ]);
        $type = MilkBuyingPriceType::create($data);
        return ApiResponse::created($type, 'Price type created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $type = MilkBuyingPriceType::findOrFail($id);
        $data = $request->validate([
            'name'        => 'sometimes|string|max:150',
            'description' => 'nullable|string|max:255',
            'active'      => 'sometimes|boolean',
        ]);
        $type->fill($data)->save();
        return ApiResponse::updated($type->fresh(), 'Price type updated');
    }

    public function destroy(int $id): JsonResponse
    {
        MilkBuyingPriceType::findOrFail($id)->delete();
        return ApiResponse::deleted('Price type deleted');
    }
}
