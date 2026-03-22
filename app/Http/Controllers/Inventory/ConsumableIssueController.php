<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ConsumableIssue;
use App\Models\GldTransaction;
use App\Models\Item;
use App\Models\StockMovement;
use App\Services\Inventory\GldTransactionService;
use App\Services\Inventory\StockMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ConsumableIssueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ConsumableIssue::with('items')->orderBy('date', 'desc')->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('from_location_id')) {
            $query->where('from_location_id', $request->from_location_id);
        }

        return ApiResponse::success($query->get(), 'Consumable issues retrieved');
    }

    public function show(int $id): JsonResponse
    {
        $issue = ConsumableIssue::with('items.item')->findOrFail($id);
        $data  = $issue->toArray();

        if ($issue->status === 'approved') {
            // Attach GL journal entries so the frontend can render the accounting view
            $data['gl_entries'] = GldTransaction::where('trans_no', $id)
                ->where('type', StockMovement::TYPE_CONSUMABLE)
                ->orderByDesc('amount') // debits first
                ->get(['account_code', 'narration', 'amount']);
        }

        return ApiResponse::success($data, 'Issue retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_location_id' => 'required|string|max:30',
            'vehicle'          => 'nullable|string|max:50',
            'shift'            => 'nullable|string|max:20',
            'date'             => ['required', 'date', new \App\Rules\WithinFiscalYear],
            'dimension_id'     => 'nullable|integer',
            'dimension2_id'    => 'nullable|integer',
            'gl_account'       => 'required|string|max:15',
            'memo'             => 'nullable|string',
            'items'            => 'required|array|min:1',
            'items.*.stock_id' => 'required|string|max:20',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.unit'     => 'nullable|string|max:20',
        ]);

        DB::transaction(function () use ($validated, &$issue) {
            $issue = ConsumableIssue::create(array_merge(
                $validated,
                ['created_by' => Auth::user()?->user_id, 'status' => 'draft', 'reference' => 'TEMP-' . uniqid()]
            ));
            $issue->update([
                'reference' => 'CONS' . str_pad($issue->id, 3, '0', STR_PAD_LEFT) . '/' . date('Y'),
            ]);
            foreach ($validated['items'] as $line) {
                $issue->items()->create($line);
            }
        });

        return ApiResponse::created($issue->load('items.item'), 'Issue created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $issue = ConsumableIssue::findOrFail($id);

        if ($issue->status !== 'draft') {
            return ApiResponse::error('Only draft issues can be edited', 422);
        }

        $validated = $request->validate([
            'from_location_id' => 'sometimes|string|max:30',
            'vehicle'          => 'nullable|string|max:50',
            'shift'            => 'nullable|string|max:20',
            'date'             => ['sometimes', 'date', new \App\Rules\WithinFiscalYear],
            'dimension_id'     => 'nullable|integer',
            'dimension2_id'    => 'nullable|integer',
            'gl_account'       => 'nullable|string|max:15',
            'memo'             => 'nullable|string',
            'items'            => 'sometimes|array|min:1',
            'items.*.stock_id' => 'required_with:items|string|max:20',
            'items.*.quantity' => 'required_with:items|numeric|min:0.0001',
            'items.*.unit'     => 'nullable|string|max:20',
        ]);

        DB::transaction(function () use ($validated, $issue) {
            $issue->fill($validated)->save();
            if (isset($validated['items'])) {
                $issue->items()->delete();
                foreach ($validated['items'] as $line) {
                    $issue->items()->create($line);
                }
            }
        });

        return ApiResponse::updated($issue->fresh()->load('items.item'), 'Issue updated');
    }

    public function submit(int $id): JsonResponse
    {
        $issue = ConsumableIssue::findOrFail($id);
        if ($issue->status !== 'draft') {
            return ApiResponse::error('Issue is not in draft status', 422);
        }
        $issue->update(['status' => 'pending']);
        return ApiResponse::updated($issue->fresh(), 'Issue submitted for approval');
    }

    public function approve(int $id): JsonResponse
    {
        $issue = ConsumableIssue::with('items.item')->findOrFail($id);

        if ($issue->status !== 'pending') {
            return ApiResponse::error('Issue is not pending approval', 422);
        }
        if (!$issue->gl_account) {
            return ApiResponse::error('A GL account must be set on this issue before approving', 422);
        }

        $shortfalls = [];

        try {
            DB::transaction(function () use ($issue, &$shortfalls) {
                $approver = Auth::user()?->user_id ?? '';

                // ── 1. Stock availability check ───────────────────────────────
                foreach ($issue->items as $item) {
                    $available = StockMovementService::availableQty(
                        $item->stock_id, $issue->from_location_id, lockForUpdate: true
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

                // ── 2. Mark approved ──────────────────────────────────────────
                $issue->update([
                    'status'      => 'approved',
                    'approved_by' => $approver,
                ]);

                // ── 3. Per-item: stock movement + GL double entry ─────────────
                foreach ($issue->items as $lineItem) {
                    $awp           = StockMovementService::weightedAveragePrice(
                        $lineItem->stock_id, $issue->from_location_id
                    );
                    $lineAmount    = round($awp * abs($lineItem->quantity), 4);
                    $inventoryGl   = $lineItem->item?->inventory_account ?? '';

                    // Stock movement — decrease consumed stock (negative qty)
                    StockMovementService::record([
                        'trans_no'  => $issue->id,
                        'type'      => StockMovement::TYPE_CONSUMABLE,
                        'stock_id'  => $lineItem->stock_id,
                        'loc_code'  => $issue->from_location_id,
                        'tran_date' => $issue->date,
                        'qty'       => -abs($lineItem->quantity),
                        'reference' => $issue->reference,
                        'comments'  => $issue->memo ?? '',
                        'user_name' => $approver,
                        'vehicle'   => $issue->vehicle ?? '',
                        'shift'     => $issue->shift ?? '',
                        'unique_key'=> $issue->reference . '-' . $lineItem->id,
                    ]);

                    // GL double entry (only if inventory account is configured)
                    if ($inventoryGl && $lineAmount > 0) {
                        GldTransactionService::doubleEntry(
                            transNo:       $issue->id,
                            type:          StockMovement::TYPE_CONSUMABLE,
                            date:          $issue->date,
                            debitAccount:  $issue->gl_account,   // expense account — DEBIT
                            creditAccount: $inventoryGl,         // inventory account — CREDIT
                            amount:        $lineAmount,
                            reference:     $issue->reference,
                            narration:     $issue->memo ?? 'Consumable issue ' . $issue->reference,
                            createdBy:     $approver,
                            extra:         [
                                'dimension_id'  => $issue->dimension_id,
                                'dimension2_id' => $issue->dimension2_id,
                            ]
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
                    'Insufficient stock at source location — ' . implode('; ', $lines),
                    422
                );
            }
            throw $e;
        }

        $fresh = $issue->fresh()->load('items.item')->toArray();
        $fresh['gl_entries'] = GldTransaction::where('trans_no', $id)
            ->where('type', StockMovement::TYPE_CONSUMABLE)
            ->orderByDesc('amount')
            ->get(['account_code', 'narration', 'amount']);

        return ApiResponse::updated($fresh, 'Issue approved');
    }

    public function reject(int $id): JsonResponse
    {
        $issue = ConsumableIssue::findOrFail($id);
        if ($issue->status !== 'pending') {
            return ApiResponse::error('Issue is not pending approval', 422);
        }
        $issue->update([
            'status'      => 'rejected',
            'approved_by' => Auth::user()?->user_id,
        ]);
        return ApiResponse::updated($issue->fresh(), 'Issue rejected');
    }

    public function destroy(int $id): JsonResponse
    {
        $issue = ConsumableIssue::findOrFail($id);
        if ($issue->status !== 'draft') {
            return ApiResponse::error('Only draft issues can be deleted', 422);
        }
        $issue->delete();
        return ApiResponse::deleted('Issue deleted');
    }
}
