<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\MilkPricePerMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MilkPriceMemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MilkPricePerMember::orderByDesc('year')->orderBy('month');
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        if ($request->filled('month')) {
            $query->where('month', $request->month);
        }
        if ($request->filled('year')) {
            $query->where('year', $request->year);
        }
        return ApiResponse::success($query->get(), 'Milk prices per member retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => 'required|string|max:100',
            'month'       => 'required|string|max:20',
            'year'        => 'required|integer|min:2000|max:2099',
            'begin_date'  => 'required|date',
            'end_date'    => 'required|date|after_or_equal:begin_date',
            'price'       => 'required|numeric|min:0',
        ]);
        $record = MilkPricePerMember::create($data);
        return ApiResponse::created($record, 'Price per member created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $record = MilkPricePerMember::findOrFail($id);
        $data   = $request->validate([
            'supplier_id' => 'sometimes|string|max:100',
            'month'       => 'sometimes|string|max:20',
            'year'        => 'sometimes|integer|min:2000|max:2099',
            'begin_date'  => 'sometimes|date',
            'end_date'    => 'sometimes|date',
            'price'       => 'sometimes|numeric|min:0',
        ]);
        $record->fill($data)->save();
        return ApiResponse::updated($record->fresh(), 'Price per member updated');
    }

    public function destroy(int $id): JsonResponse
    {
        MilkPricePerMember::findOrFail($id)->delete();
        return ApiResponse::deleted('Price per member deleted');
    }
}
