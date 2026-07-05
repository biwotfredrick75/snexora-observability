<?php

namespace App\Http\Controllers\CasualWorkers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CasualWorker;
use App\Models\CasualWorkerPayRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CasualWorkerPayRateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CasualWorkerPayRate::with(['worker', 'trade'])
            ->orderByDesc('effective_from');

        if ($request->filled('worker_id')) {
            $query->where('worker_id', $request->worker_id);
        }
        if ($request->filled('trade_id')) {
            $query->where('trade_id', $request->trade_id);
        }

        return ApiResponse::success($query->get(), 'Pay rates retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'worker_id'           => 'nullable|integer|exists:casual_workers,id',
            'trade_id'            => 'nullable|integer|exists:casual_worker_trades,id',
            'rate_type'           => 'required|in:daily,hourly',
            'rate'                => 'required|numeric|min:0',
            'overtime_multiplier' => 'nullable|numeric|min:1|max:5',
            'transport_allowance' => 'nullable|numeric|min:0',
            'meal_allowance'      => 'nullable|numeric|min:0',
            'effective_from'      => 'required|date',
        ]);

        if (empty($data['worker_id']) && empty($data['trade_id'])) {
            return ApiResponse::error('Either worker_id or trade_id is required.', 422);
        }

        $rate = CasualWorkerPayRate::create($data);
        return ApiResponse::created($rate->load(['worker', 'trade']), 'Pay rate created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rate = CasualWorkerPayRate::findOrFail($id);

        $data = $request->validate([
            'rate_type'           => 'required|in:daily,hourly',
            'rate'                => 'required|numeric|min:0',
            'overtime_multiplier' => 'nullable|numeric|min:1|max:5',
            'transport_allowance' => 'nullable|numeric|min:0',
            'meal_allowance'      => 'nullable|numeric|min:0',
            'effective_from'      => 'required|date',
        ]);

        $rate->update($data);
        return ApiResponse::updated($rate->load(['worker', 'trade']), 'Pay rate updated');
    }

    public function destroy(int $id): JsonResponse
    {
        CasualWorkerPayRate::findOrFail($id)->delete();
        return ApiResponse::deleted('Pay rate deleted');
    }
}
