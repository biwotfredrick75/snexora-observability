<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PackagingQuantity;
use App\Models\PackagingType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PackagingQuantityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PackagingQuantity::with('packagingType')->orderBy('created_at', 'desc');
        if (!$request->boolean('inactive')) {
            $query->where('inactive', false);
        }
        return ApiResponse::success($query->get(), 'Packaging quantities retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'packaging_type_id' => 'nullable|integer|exists:packaging_types,id',
            'quantity'          => 'required|numeric|min:0',
            'condition'         => 'nullable|string|max:50',
            'location_type'     => 'nullable|string|max:50',
            'location_code'     => 'nullable|string|max:20',
            'notes'             => 'nullable|string',
        ]);
        $pq = PackagingQuantity::create($validated);
        $pq->load('packagingType');
        return ApiResponse::created($pq, 'Packaging quantity added');
    }

    public function destroy(int $id): JsonResponse
    {
        $pq = PackagingQuantity::findOrFail($id);
        $pq->update(['inactive' => true]);
        return ApiResponse::deleted('Packaging quantity removed');
    }

    public function summary(): JsonResponse
    {
        $rows = DB::table('packaging_quantities as pq')
            ->join('packaging_types as pt', 'pq.packaging_type_id', '=', 'pt.id')
            ->where('pq.inactive', false)
            ->select(
                'pt.name as type_name',
                'pq.condition',
                DB::raw('SUM(pq.quantity) as total_quantity')
            )
            ->groupBy('pt.name', 'pq.condition')
            ->orderBy('pt.name')
            ->get();

        return ApiResponse::success($rows, 'Packaging summary retrieved');
    }
}
