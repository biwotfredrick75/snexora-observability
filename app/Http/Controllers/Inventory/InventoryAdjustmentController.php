<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\InventoryAdjustment;
use App\Models\StockMovement;
use App\Services\Inventory\StockMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventoryAdjustmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = InventoryAdjustment::with('items')->orderBy('date', 'desc')->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('location_id')) {
            $query->where('location_id', $request->location_id);
        }

        return ApiResponse::success($query->get(), 'Adjustments retrieved');
    }

    public function show(int $id): JsonResponse
    {
        $adjustment = InventoryAdjustment::with('items.item')->findOrFail($id);
        return ApiResponse::success($adjustment, 'Adjustment retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id'            => 'required|string|max:30',
            'date'                   => ['required', 'date', new \App\Rules\WithinFiscalYear],
            'memo'                   => 'nullable|string',
            'items'                  => 'required|array|min:1',
            'items.*.stock_id'       => 'required|string|max:20',
            'items.*.quantity'       => 'required|numeric',
            'items.*.unit'           => 'nullable|string|max:20',
            'items.*.unit_cost'      => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($validated, &$adjustment) {
            $adjustment = InventoryAdjustment::create(array_merge(
                $validated,
                ['created_by' => Auth::user()?->user_id, 'status' => 'draft', 'reference' => 'TEMP-' . uniqid()]
            ));
            $adjustment->update([
                'reference' => 'ADJ' . str_pad($adjustment->id, 3, '0', STR_PAD_LEFT) . '/' . date('Y'),
            ]);
            foreach ($validated['items'] as $line) {
                $adjustment->items()->create($line);
            }
        });

        return ApiResponse::created($adjustment->load('items.item'), 'Adjustment created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $adjustment = InventoryAdjustment::findOrFail($id);

        if ($adjustment->status !== 'draft') {
            return ApiResponse::error('Only draft adjustments can be edited', 422);
        }

        $validated = $request->validate([
            'location_id'       => 'sometimes|string|max:30',
            'date'              => ['sometimes', 'date', new \App\Rules\WithinFiscalYear],
            'memo'              => 'nullable|string',
            'items'             => 'sometimes|array|min:1',
            'items.*.stock_id'  => 'required_with:items|string|max:20',
            'items.*.quantity'  => 'required_with:items|numeric',
            'items.*.unit'      => 'nullable|string|max:20',
            'items.*.unit_cost' => 'nullable|numeric|min:0',
        ]);

        DB::transaction(function () use ($validated, $adjustment) {
            $adjustment->fill($validated)->save();
            if (isset($validated['items'])) {
                $adjustment->items()->delete();
                foreach ($validated['items'] as $line) {
                    $adjustment->items()->create($line);
                }
            }
        });

        return ApiResponse::updated($adjustment->fresh()->load('items.item'), 'Adjustment updated');
    }

    public function process(int $id): JsonResponse
    {
        $adjustment = InventoryAdjustment::with('items')->findOrFail($id);
        if ($adjustment->status !== 'draft') {
            return ApiResponse::error('Adjustment is not in draft status', 422);
        }

        $shortfalls = [];

        try {
            DB::transaction(function () use ($adjustment, &$shortfalls) {
                $processor = Auth::user()?->user_id ?? '';

                // ── Check available stock for reduction items (negative qty) ──
                foreach ($adjustment->items as $item) {
                    if ($item->quantity < 0) {
                        $available = StockMovementService::availableQty(
                            $item->stock_id, $adjustment->location_id, lockForUpdate: true
                        );
                        if ($available < abs($item->quantity)) {
                            $shortfalls[] = [
                                'stock_id'  => $item->stock_id,
                                'required'  => abs($item->quantity),
                                'available' => max(0, $available),
                            ];
                        }
                    }
                }

                if (!empty($shortfalls)) {
                    throw new \RuntimeException('insufficient_stock');
                }

                // ── Mark as processed ─────────────────────────────────────────
                $adjustment->update(['status' => 'processed']);

                // ── Write one stock movement per item ─────────────────────────
                foreach ($adjustment->items as $item) {
                    // For adjustments, use the entered unit_cost as price if provided,
                    // otherwise fall back to AWP (handled by the service).
                    $movData = [
                        'trans_no'  => $adjustment->id,
                        'type'      => StockMovement::TYPE_ADJUSTMENT,
                        'stock_id'  => $item->stock_id,
                        'loc_code'  => $adjustment->location_id,
                        'tran_date' => $adjustment->date,
                        'qty'       => $item->quantity,
                        'reference' => $adjustment->reference,
                        'comments'  => $adjustment->memo ?? '',
                        'user_name' => $processor,
                        'unique_key'=> $adjustment->reference . '-' . $item->id,
                    ];
                    if ($item->unit_cost !== null && $item->unit_cost > 0) {
                        $movData['price']         = $item->unit_cost;
                        $movData['standard_cost'] = $item->unit_cost;
                    }
                    StockMovementService::record($movData);
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'insufficient_stock') {
                $lines = array_map(
                    fn($s) => "{$s['stock_id']}: need {$s['required']}, have {$s['available']}",
                    $shortfalls
                );
                return ApiResponse::error(
                    'Insufficient stock for reduction — ' . implode('; ', $lines),
                    422
                );
            }
            throw $e;
        }

        return ApiResponse::updated($adjustment->fresh()->load('items'), 'Adjustment processed');
    }

    public function destroy(int $id): JsonResponse
    {
        $adjustment = InventoryAdjustment::findOrFail($id);
        if ($adjustment->status !== 'draft') {
            return ApiResponse::error('Only draft adjustments can be deleted', 422);
        }
        $adjustment->delete();
        return ApiResponse::deleted('Adjustment deleted');
    }
}
