<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\StockMovement;
use App\Models\StockRequisition;
use App\Services\Inventory\StockMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StockRequisitionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StockRequisition::with('items')->orderBy('date', 'desc')->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('from_location_id')) {
            $query->where('from_location_id', $request->from_location_id);
        }

        return ApiResponse::success($query->get(), 'Requisitions retrieved');
    }

    public function show(int $id): JsonResponse
    {
        $req = StockRequisition::with('items.item')->findOrFail($id);
        return ApiResponse::success($req, 'Requisition retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_location_id' => 'required|string|max:30',
            'to_location_id'   => 'required|string|max:30',
            'date'             => ['required', 'date', new \App\Rules\WithinFiscalYear],
            'person'           => 'nullable|string|max:100',
            'gl_account'       => 'nullable|string|max:15',
            'memo'             => 'nullable|string',
            'items'            => 'required|array|min:1',
            'items.*.stock_id' => 'required|string|max:20',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.unit'     => 'nullable|string|max:20',
        ]);

        DB::transaction(function () use ($validated, &$requisition) {
            $requisition = StockRequisition::create(array_merge(
                $validated,
                ['raised_by' => Auth::user()?->user_id, 'status' => 'draft', 'reference' => 'TEMP-' . uniqid()]
            ));
            $requisition->update([
                'reference' => 'REQ' . str_pad($requisition->id, 3, '0', STR_PAD_LEFT) . '/' . date('Y'),
            ]);
            foreach ($validated['items'] as $line) {
                $requisition->items()->create($line);
            }
        });

        return ApiResponse::created($requisition->load('items.item'), 'Requisition created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $requisition = StockRequisition::findOrFail($id);

        if ($requisition->status !== 'draft') {
            return ApiResponse::error('Only draft requisitions can be edited', 422);
        }

        $validated = $request->validate([
            'from_location_id' => 'sometimes|string|max:30',
            'to_location_id'   => 'sometimes|string|max:30',
            'date'             => ['sometimes', 'date', new \App\Rules\WithinFiscalYear],
            'person'           => 'nullable|string|max:100',
            'gl_account'       => 'nullable|string|max:15',
            'memo'             => 'nullable|string',
            'items'            => 'sometimes|array|min:1',
            'items.*.stock_id' => 'required_with:items|string|max:20',
            'items.*.quantity' => 'required_with:items|numeric|min:0.0001',
            'items.*.unit'     => 'nullable|string|max:20',
        ]);

        DB::transaction(function () use ($validated, $requisition) {
            $requisition->fill($validated)->save();
            if (isset($validated['items'])) {
                $requisition->items()->delete();
                foreach ($validated['items'] as $line) {
                    $requisition->items()->create($line);
                }
            }
        });

        return ApiResponse::updated($requisition->fresh()->load('items.item'), 'Requisition updated');
    }

    public function submit(int $id): JsonResponse
    {
        $req = StockRequisition::findOrFail($id);
        if ($req->status !== 'draft') {
            return ApiResponse::error('Requisition is not in draft status', 422);
        }
        $req->update(['status' => 'pending']);
        return ApiResponse::updated($req->fresh(), 'Requisition submitted');
    }

    public function approve(int $id): JsonResponse
    {
        $req = StockRequisition::findOrFail($id);
        if ($req->status !== 'pending') {
            return ApiResponse::error('Requisition is not pending approval', 422);
        }
        $req->update([
            'status'      => 'approved',
            'approved_by' => Auth::user()?->user_id,
        ]);
        return ApiResponse::updated($req->fresh(), 'Requisition approved');
    }

    public function reject(int $id): JsonResponse
    {
        $req = StockRequisition::findOrFail($id);
        if ($req->status !== 'pending') {
            return ApiResponse::error('Requisition is not pending approval', 422);
        }
        $req->update([
            'status'      => 'rejected',
            'approved_by' => Auth::user()?->user_id,
        ]);
        return ApiResponse::updated($req->fresh(), 'Requisition rejected');
    }

    public function dispatch(int $id): JsonResponse
    {
        $req = StockRequisition::with('items')->findOrFail($id);
        if ($req->status !== 'approved') {
            return ApiResponse::error('Requisition must be approved before dispatching', 422);
        }

        $shortfalls = [];

        try {
            DB::transaction(function () use ($req, &$shortfalls) {
                $dispatcher = Auth::user()?->user_id ?? '';

                // ── Check available stock at source location ──────────────────
                foreach ($req->items as $item) {
                    $available = StockMovementService::availableQty(
                        $item->stock_id, $req->from_location_id, lockForUpdate: true
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

                // ── Mark dispatched ───────────────────────────────────────────
                $req->update(['status' => 'dispatched']);

                // ── Double-entry stock movements ──────────────────────────────
                $shared = [
                    'trans_no'  => $req->id,
                    'type'      => StockMovement::TYPE_REQUISITION,
                    'tran_date' => $req->date,
                    'reference' => $req->reference,
                    'comments'  => $req->memo ?? '',
                    'user_name' => $dispatcher,
                    // price and standard_cost resolved per item by StockMovementService
                ];

                foreach ($req->items as $item) {
                    // OUT — deduct from source store (negative)
                    StockMovementService::record(array_merge($shared, [
                        'stock_id'   => $item->stock_id,
                        'loc_code'   => $req->from_location_id,
                        'qty'        => -abs($item->quantity),
                        'unique_key' => $req->reference . '-OUT-' . $item->id,
                    ]));

                    // IN — add to destination store (positive)
                    StockMovementService::record(array_merge($shared, [
                        'stock_id'      => $item->stock_id,
                        'loc_code'      => $req->to_location_id,
                        'loc_code_from' => $req->from_location_id,
                        'qty'           => abs($item->quantity),
                        'unique_key'    => $req->reference . '-IN-' . $item->id,
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

        return ApiResponse::updated($req->fresh()->load('items'), 'Requisition dispatched');
    }

    public function destroy(int $id): JsonResponse
    {
        $req = StockRequisition::findOrFail($id);
        if ($req->status !== 'draft') {
            return ApiResponse::error('Only draft requisitions can be deleted', 422);
        }
        $req->delete();
        return ApiResponse::deleted('Requisition deleted');
    }
}
