<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\StoreAllocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreAllocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StoreAllocation::orderBy('store');
        if (!$request->boolean('inactive')) {
            $query->where('inactive', false);
        }
        return ApiResponse::success($query->get(), 'Store allocations retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id'  => 'nullable|integer',
            'store'    => 'required|string|max:20',
            'activity' => 'required|in:Issuing,Returns,Both',
        ]);
        $allocation = StoreAllocation::create($validated);
        return ApiResponse::created($allocation, 'Store allocation created');
    }

    public function destroy(int $id): JsonResponse
    {
        $allocation = StoreAllocation::findOrFail($id);
        $allocation->update(['inactive' => true]);
        return ApiResponse::deleted('Store allocation removed');
    }
}
