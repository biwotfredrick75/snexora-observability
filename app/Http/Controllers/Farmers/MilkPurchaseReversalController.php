<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\MilkPurchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MilkPurchaseReversalController extends Controller
{
    // ── Dropdown form data ────────────────────────────────────────────────────
    public function formData(): JsonResponse
    {
        return ApiResponse::success([
            'locations' => DB::table('inventory_locations')
                ->where('inactive', false)
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'suppliers' => DB::table('farmers')
                ->where('status', 'active')
                ->orderBy('full_name')
                ->get(['id', 'farmer_no as member_no', 'full_name as name']),
            'routes' => DB::table('milk_routes')
                ->orderBy('route_name')
                ->get(['id', 'route_name']),
        ], 'Form data retrieved');
    }

    // ── Search approved milk purchases ────────────────────────────────────────
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'start_date'  => 'required|date',
            'end_date'    => 'required|date|after_or_equal:start_date',
            'grader_id'   => 'nullable|integer|exists:inventory_locations,id',
            'farmer_id'   => 'nullable|integer|exists:farmers,id',
        ]);

        $query = DB::table('milk_purchases as mp')
            ->leftJoin('inventory_locations as il', 'il.id', '=', 'mp.grader_id')
            ->leftJoin('milk_routes as mr',         'mr.id', '=', 'mp.route_id')
            ->leftJoin('milk_collection_shifts as sh', 'sh.id', '=', 'mp.shift_id')
            ->whereDate('mp.invoice_date', '>=', $request->start_date)
            ->whereDate('mp.invoice_date', '<=', $request->end_date)
            ->where('mp.status', 'approved')
            ->when($request->filled('grader_id'), fn ($q) => $q->where('mp.grader_id', $request->grader_id))
            ->when($request->filled('farmer_id'), fn ($q) => $q->whereExists(function ($sub) use ($request) {
                $sub->select(DB::raw(1))
                    ->from('milk_purchase_items')
                    ->whereColumn('milk_purchase_items.purchase_id', 'mp.id')
                    ->where('milk_purchase_items.farmer_id', $request->farmer_id);
            }))
            ->select([
                'mp.id',
                'mp.reference_no   as trans_no',
                'mp.invoice_date   as date',
                'mp.total_qty      as quantity',
                'mp.total_amount   as amount',
                'mp.status',
                'sh.description    as shift',
                'mr.route_name     as route',
                'il.name           as location',
                'il.code           as location_code',
            ])
            ->orderByDesc('mp.invoice_date')
            ->limit(300);

        $rows = $query->get();

        // Fetch primary farmer name for each purchase
        $purchaseIds = $rows->pluck('id');
        $farmerNames = DB::table('milk_purchase_items as mpi')
            ->join('farmers as f', 'f.id', '=', 'mpi.farmer_id')
            ->whereIn('mpi.purchase_id', $purchaseIds)
            ->select('mpi.purchase_id', DB::raw('MIN(f.full_name) as name'))
            ->groupBy('mpi.purchase_id')
            ->pluck('name', 'purchase_id');

        $rows = $rows->map(function ($row) use ($farmerNames) {
            $row->name = $farmerNames[$row->id] ?? '—';
            return $row;
        });

        return ApiResponse::success([
            'rows'         => $rows,
            'total_qty'    => $rows->sum('quantity'),
            'total_amount' => $rows->sum('amount'),
        ], count($rows) . ' purchase(s) found');
    }

    // ── Reverse a single purchase ─────────────────────────────────────────────
    public function reverse(int $id): JsonResponse
    {
        return $this->doReverse([$id], auth()->user()?->user_id ?? 'system');
    }

    // ── Bulk reverse ──────────────────────────────────────────────────────────
    public function bulkReverse(Request $request): JsonResponse
    {
        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);
        return $this->doReverse($request->ids, auth()->user()?->user_id ?? 'system');
    }

    // ── Core reversal logic ───────────────────────────────────────────────────
    private function doReverse(array $ids, string $user): JsonResponse
    {
        $purchases = MilkPurchase::whereIn('id', $ids)
            ->where('status', 'approved')
            ->get();

        if ($purchases->isEmpty()) {
            return ApiResponse::validationError(['error' => 'No approved purchases found for the given IDs.']);
        }

        DB::beginTransaction();
        try {
            $reversed = 0;

            foreach ($purchases as $purchase) {
                // Mark purchase as reversed
                $purchase->update([
                    'status'      => 'reversed',
                    'approved_by' => $user,
                ]);

                $ref     = $purchase->reference_no ?? ('MPB-' . str_pad($purchase->id, 6, '0', STR_PAD_LEFT));
                $revRef  = 'REV-' . ltrim($ref, 'MPB-');
                $date    = $purchase->invoice_date instanceof \Carbon\Carbon
                    ? $purchase->invoice_date->toDateString()
                    : $purchase->invoice_date;

                // Reverse stock movements (negate qty)
                $stockMoves = DB::table('stock_movements')
                    ->where('trans_no', $purchase->id)
                    ->where('type', 25)  // GRN receive
                    ->get();

                foreach ($stockMoves as $sm) {
                    DB::table('stock_movements')->insert([
                        'trans_no'      => $purchase->id,
                        'stock_id'      => $sm->stock_id,
                        'type'          => 25,
                        'loc_code'      => $sm->loc_code,
                        'route_id'      => $sm->route_id ?? null,
                        'shift'         => $sm->shift ?? null,
                        'tran_date'     => $date,
                        'qty'           => -((float) $sm->qty),
                        'price'         => $sm->price,
                        'standard_cost' => $sm->standard_cost ?? 0,
                        'reference'     => $revRef,
                        'comments'      => 'Reversal of ' . $ref,
                        'unique_key'    => null,
                    ]);
                }

                // Reverse GL entries (negate amounts)
                $glEntries = DB::table('gld_transactions')
                    ->where('trans_no', $purchase->id)
                    ->get();

                foreach ($glEntries as $gl) {
                    DB::table('gld_transactions')->insert([
                        'type'         => $gl->type,
                        'trans_no'     => $purchase->id,
                        'tran_date'    => $date,
                        'account_code' => $gl->account_code,
                        'narration'    => 'Reversal — ' . ($gl->narration ?? $ref),
                        'reference'    => $revRef,
                        'amount'       => -((float) $gl->amount),
                        'created_by'   => $user,
                    ]);
                }

                $reversed++;
            }

            DB::commit();

            return ApiResponse::success([
                'reversed' => $reversed,
            ], "{$reversed} purchase(s) reversed successfully");

        } catch (\Throwable $e) {
            DB::rollBack();
            return ApiResponse::validationError(['error' => $e->getMessage()]);
        }
    }
}
