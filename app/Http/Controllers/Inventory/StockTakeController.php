<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\StockTake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StockTakeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StockTake::with('items')->orderBy('date', 'desc')->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('location_id')) {
            $query->where('location_id', $request->location_id);
        }
        if ($request->filled('from')) {
            $query->where('date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('date', '<=', $request->to);
        }

        return ApiResponse::success($query->get(), 'Stock takes retrieved');
    }

    public function show(int $id): JsonResponse
    {
        $take = StockTake::with('items.item')->findOrFail($id);
        return ApiResponse::success($take, 'Stock take retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'required|string|max:30',
            'date'        => ['required', 'date', new \App\Rules\WithinFiscalYear],
            'memo'        => 'nullable|string',
            'items'       => 'required|array|min:1',
            'items.*.stock_id'    => 'required|string|max:20',
            'items.*.system_qty'  => 'nullable|numeric',
            'items.*.counted_qty' => 'nullable|numeric|min:0',
            'items.*.unit'        => 'nullable|string|max:20',
        ]);

        DB::transaction(function () use ($validated, &$take) {
            $take = StockTake::create(array_merge(
                $validated,
                ['created_by' => Auth::user()?->user_id, 'status' => 'draft', 'reference' => 'TEMP-' . uniqid()]
            ));
            $take->update([
                'reference' => 'ST' . str_pad($take->id, 3, '0', STR_PAD_LEFT) . '/' . date('Y'),
            ]);
            foreach ($validated['items'] as $line) {
                $take->items()->create($line);
            }
        });

        return ApiResponse::created($take->load('items.item'), 'Stock take created');
    }

    public function submit(int $id): JsonResponse
    {
        $take = StockTake::findOrFail($id);
        if ($take->status !== 'draft') {
            return ApiResponse::error('Stock take is not in draft status', 422);
        }
        $take->update(['status' => 'pending']);
        return ApiResponse::updated($take->fresh(), 'Stock take submitted for approval');
    }

    public function approve(int $id): JsonResponse
    {
        $take = StockTake::findOrFail($id);
        if ($take->status !== 'pending') {
            return ApiResponse::error('Stock take is not pending approval', 422);
        }
        $take->update([
            'status'      => 'approved',
            'approved_by' => Auth::user()?->user_id,
        ]);
        return ApiResponse::updated($take->fresh(), 'Stock take approved');
    }

    public function destroy(int $id): JsonResponse
    {
        $take = StockTake::findOrFail($id);
        if ($take->status !== 'draft') {
            return ApiResponse::error('Only draft stock takes can be deleted', 422);
        }
        $take->delete();
        return ApiResponse::deleted('Stock take deleted');
    }
}
