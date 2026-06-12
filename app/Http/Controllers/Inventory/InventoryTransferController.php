<?php

namespace App\Http\Controllers\Inventory;

use App\Events\DashboardEvent;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferItem;
use App\Models\StockMovement;
use App\Services\Inventory\StockMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventoryTransferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = InventoryTransfer::with('items')->orderBy('date', 'desc')->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('from_location_id')) {
            $query->where('from_location_id', $request->from_location_id);
        }
        if ($request->filled('to_location_id')) {
            $query->where('to_location_id', $request->to_location_id);
        }
        if ($request->filled('from')) {
            $query->whereDate('date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('date', '<=', $request->to);
        }

        return ApiResponse::success($query->get(), 'Transfers retrieved');
    }

    public function show(int $id): JsonResponse
    {
        $transfer = InventoryTransfer::with('items.item')->findOrFail($id);
        return ApiResponse::success($transfer, 'Transfer retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_location_id' => 'required|string|max:30',
            'to_location_id'   => 'required|string|max:30',
            'date'             => ['required', 'date', new \App\Rules\WithinFiscalYear],
            'vehicle'          => 'nullable|string|max:50',
            'shift'            => 'nullable|string|max:20',
            'dimension_id'     => 'nullable|integer',
            'dimension2_id'    => 'nullable|integer',
            'gl_account'       => 'nullable|string|max:15',
            'memo'             => 'nullable|string',
            'items'            => 'required|array|min:1',
            'items.*.stock_id' => 'required|string|max:20',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.unit'     => 'nullable|string|max:20',
        ]);

        if ($validated['from_location_id'] === $validated['to_location_id']) {
            return ApiResponse::error('Source and destination location must be different', 422);
        }

        DB::transaction(function () use ($validated, &$transfer) {
            $transfer = InventoryTransfer::create(array_merge(
                $validated,
                ['created_by' => Auth::user()?->user_id, 'status' => 'draft', 'reference' => 'TEMP-' . uniqid()]
            ));
            $transfer->update([
                'reference' => 'TRF' . str_pad($transfer->id, 3, '0', STR_PAD_LEFT) . '/' . date('Y'),
            ]);
            foreach ($validated['items'] as $line) {
                $transfer->items()->create($line);
            }
        });

        broadcast(new DashboardEvent('transfer', 'created', ['ref' => $transfer->reference]));
        return ApiResponse::created($transfer->load('items.item'), 'Transfer created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $transfer = InventoryTransfer::findOrFail($id);

        if ($transfer->status !== 'draft') {
            return ApiResponse::error('Only draft transfers can be edited', 422);
        }

        $validated = $request->validate([
            'from_location_id' => 'sometimes|string|max:30',
            'to_location_id'   => 'sometimes|string|max:30',
            'date'             => ['sometimes', 'date', new \App\Rules\WithinFiscalYear],
            'vehicle'          => 'nullable|string|max:50',
            'shift'            => 'nullable|string|max:20',
            'dimension_id'     => 'nullable|integer',
            'dimension2_id'    => 'nullable|integer',
            'gl_account'       => 'nullable|string|max:15',
            'memo'             => 'nullable|string',
            'items'            => 'sometimes|array|min:1',
            'items.*.stock_id' => 'required_with:items|string|max:20',
            'items.*.quantity' => 'required_with:items|numeric|min:0.0001',
            'items.*.unit'     => 'nullable|string|max:20',
        ]);

        DB::transaction(function () use ($validated, $transfer) {
            $transfer->fill($validated)->save();
            if (isset($validated['items'])) {
                $transfer->items()->delete();
                foreach ($validated['items'] as $line) {
                    $transfer->items()->create($line);
                }
            }
        });

        return ApiResponse::updated($transfer->fresh()->load('items.item'), 'Transfer updated');
    }

    public function submit(int $id): JsonResponse
    {
        $transfer = InventoryTransfer::findOrFail($id);
        if ($transfer->status !== 'draft') {
            return ApiResponse::error('Transfer is not in draft status', 422);
        }
        $transfer->update(['status' => 'pending']);
        broadcast(new DashboardEvent('transfer', 'submitted', ['ref' => $transfer->reference]));
        return ApiResponse::updated($transfer->fresh(), 'Transfer submitted for approval');
    }

    public function approve(int $id): JsonResponse
    {
        $transfer = InventoryTransfer::with('items')->findOrFail($id);
        if ($transfer->status !== 'pending') {
            return ApiResponse::error('Transfer is not pending approval', 422);
        }

        $shortfalls = [];

        try {
            DB::transaction(function () use ($transfer, &$shortfalls) {
                $approver = Auth::user()?->user_id ?? '';

                // ── Stock availability check (locked for concurrency safety) ──
                foreach ($transfer->items as $item) {
                    $available = StockMovementService::availableQty(
                        $item->stock_id, $transfer->from_location_id, lockForUpdate: true
                    );
                    if ($available < $item->quantity) {
                        $shortfalls[] = [
                            'stock_id'  => $item->stock_id,
                            'required'  => $item->quantity,
                            'available' => max(0, $available),
                        ];
                    }
                }

                if (!empty($shortfalls)) {
                    throw new \RuntimeException('insufficient_stock');
                }

                // ── Approve and write double-entry movements ──────────────────
                $transfer->update([
                    'status'      => 'approved',
                    'approved_by' => $approver,
                ]);

                $shared = [
                    'trans_no'  => $transfer->id,
                    'type'      => StockMovement::TYPE_TRANSFER,
                    'tran_date' => $transfer->date,
                    'reference' => $transfer->reference,
                    'comments'  => $transfer->memo ?? '',
                    'user_name' => $approver,
                    'vehicle'   => $transfer->vehicle ?? '',
                    'shift'     => $transfer->shift ?? '',
                    // price and standard_cost resolved per item by StockMovementService
                ];

                foreach ($transfer->items as $item) {
                    // OUT — deduct from source location (negative qty)
                    StockMovementService::record(array_merge($shared, [
                        'stock_id'   => $item->stock_id,
                        'loc_code'   => $transfer->from_location_id,
                        'qty'        => -abs($item->quantity),
                        'unique_key' => $transfer->reference . '-OUT-' . $item->id,
                    ]));

                    // IN — add to destination location (positive qty)
                    StockMovementService::record(array_merge($shared, [
                        'stock_id'      => $item->stock_id,
                        'loc_code'      => $transfer->to_location_id,
                        'loc_code_from' => $transfer->from_location_id,
                        'qty'           => abs($item->quantity),
                        'unique_key'    => $transfer->reference . '-IN-' . $item->id,
                    ]));
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'insufficient_stock') {
                $lines = array_map(
                    fn($s) => "{$s['stock_id']}: need {$s['required']}, have {$s['available']}",
                    $shortfalls
                );
                return ApiResponse::error(
                    'Insufficient stock at source location — ' . implode('; ', $lines),
                    422
                );
            }
            throw $e;
        }

        broadcast(new DashboardEvent('transfer', 'approved', ['ref' => $transfer->reference]));
        return ApiResponse::updated($transfer->fresh()->load('items'), 'Transfer approved');
    }

    public function reject(int $id): JsonResponse
    {
        $transfer = InventoryTransfer::findOrFail($id);
        if ($transfer->status !== 'pending') {
            return ApiResponse::error('Transfer is not pending approval', 422);
        }
        $transfer->update([
            'status'      => 'rejected',
            'approved_by' => Auth::user()?->user_id,
        ]);
        broadcast(new DashboardEvent('transfer', 'rejected', ['ref' => $transfer->reference]));
        return ApiResponse::updated($transfer->fresh(), 'Transfer rejected');
    }

    public function destroy(int $id): JsonResponse
    {
        $transfer = InventoryTransfer::findOrFail($id);
        if ($transfer->status !== 'draft') {
            return ApiResponse::error('Only draft transfers can be deleted', 422);
        }
        $transfer->delete();
        return ApiResponse::deleted('Transfer deleted');
    }
}
