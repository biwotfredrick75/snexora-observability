<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\GldTransaction;
use App\Models\Item;
use App\Models\InventoryAdjustment;
use App\Models\StockMovement;
use App\Services\Inventory\GldTransactionService;
use App\Services\Inventory\StockMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InventoryAdjustmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = InventoryAdjustment::with('items', 'reason')->orderBy('date', 'desc')->orderBy('id', 'desc');

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
        $adjustment = InventoryAdjustment::with('items.item', 'reason')->findOrFail($id);
        $data = $adjustment->toArray();

        if ($adjustment->status === 'processed') {
            $data['gl_entries'] = GldTransaction::where('trans_no', $id)
                ->where('type', StockMovement::TYPE_ADJUSTMENT)
                ->orderByDesc('amount')
                ->get(['account_code', 'narration', 'amount']);
        }

        return ApiResponse::success($data, 'Adjustment retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id'            => 'required|string|max:30',
            'reason_id'              => 'nullable|exists:adjustment_reasons,id',
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
            'reason_id'         => 'nullable|exists:adjustment_reasons,id',
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
        $adjustment = InventoryAdjustment::with('items', 'reason')->findOrFail($id);
        if ($adjustment->status !== 'draft') {
            return ApiResponse::error('Adjustment is not in draft status', 422);
        }
        if (!$adjustment->reason) {
            return ApiResponse::error('A reason must be set on this adjustment before processing', 422);
        }
        if (!$adjustment->reason->gl_account) {
            return ApiResponse::error('The selected reason has no GL account configured — set one under Setup > Adjustment Reasons', 422);
        }

        $shortfalls = [];

        try {
            DB::transaction(function () use ($adjustment, &$shortfalls) {
                $processor = Auth::user()?->user_id ?? '';
                $glSetting = DB::table('gl_settings')->first();
                $reasonGl  = $adjustment->reason->gl_account;

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

                // ── Write one stock movement + GL double entry per item ────────
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
                    $unitCost = null;
                    if ($item->unit_cost !== null && $item->unit_cost > 0) {
                        $unitCost = (float) $item->unit_cost;
                        $movData['price']         = $unitCost;
                        $movData['standard_cost'] = $unitCost;
                    }
                    StockMovementService::record($movData);

                    // ── GL: debit/credit the reason account against inventory ──
                    $itemMaster = Item::where('stock_id', $item->stock_id)
                        ->select('inventory_account', 'adjustment_account')
                        ->first();

                    $inventoryGl = ($itemMaster->inventory_account ?? null)
                        ?: ($glSetting->items_inventory_account ?? null)
                        ?: 'INVENTORY';

                    // Item-level adjustment_account (if set) overrides the reason's
                    // default so individual items can route to a more specific account.
                    $lossGl = ($itemMaster->adjustment_account ?? null) ?: $reasonGl;

                    $amount = round(abs($item->quantity) * ($unitCost ?? StockMovementService::weightedAveragePrice(
                        $item->stock_id, $adjustment->location_id
                    )), 4);

                    if ($amount <= 0) continue;

                    if ($item->quantity < 0) {
                        // Loss — DR reason expense, CR inventory
                        GldTransactionService::doubleEntry(
                            transNo:      $adjustment->id,
                            type:         StockMovement::TYPE_ADJUSTMENT,
                            date:         $adjustment->date,
                            debitAccount: $lossGl,
                            creditAccount: $inventoryGl,
                            amount:       $amount,
                            reference:    $adjustment->reference,
                            narration:    $adjustment->reason->label . ' — ' . $adjustment->reference,
                            createdBy:    $processor,
                        );
                    } else {
                        // Gain / found stock — DR inventory, CR reason account
                        GldTransactionService::doubleEntry(
                            transNo:      $adjustment->id,
                            type:         StockMovement::TYPE_ADJUSTMENT,
                            date:         $adjustment->date,
                            debitAccount: $inventoryGl,
                            creditAccount: $lossGl,
                            amount:       $amount,
                            reference:    $adjustment->reference,
                            narration:    $adjustment->reason->label . ' — ' . $adjustment->reference,
                            createdBy:    $processor,
                        );
                    }
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
