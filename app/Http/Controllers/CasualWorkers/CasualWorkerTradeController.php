<?php

namespace App\Http\Controllers\CasualWorkers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CasualWorkerTrade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CasualWorkerTradeController extends Controller
{
    public function index(): JsonResponse
    {
        $trades = CasualWorkerTrade::withCount('workers')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        return ApiResponse::success($trades, 'Trades retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'               => 'required|string|max:100|unique:casual_worker_trades,name',
            'default_daily_rate' => 'nullable|numeric|min:0',
            'sort_order'         => 'nullable|integer',
        ]);

        $trade = CasualWorkerTrade::create($data);
        return ApiResponse::created($trade, 'Trade created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $trade = CasualWorkerTrade::findOrFail($id);

        $data = $request->validate([
            'name'               => "required|string|max:100|unique:casual_worker_trades,name,{$id}",
            'default_daily_rate' => 'nullable|numeric|min:0',
            'sort_order'         => 'nullable|integer',
        ]);

        $trade->update($data);
        return ApiResponse::updated($trade, 'Trade updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $trade = CasualWorkerTrade::withCount('workers')->findOrFail($id);

        if ($trade->workers_count > 0) {
            return ApiResponse::error('Cannot delete a trade with assigned workers.', 409);
        }

        $trade->delete();
        return ApiResponse::deleted('Trade deleted');
    }
}
