<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\GraderDeduction;
use App\Models\InventoryAdjustment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Optionally attributes a processed RAW MILK spillage/wastage write-off
 * (created via the generic Inventory Adjustments engine — see
 * InventoryAdjustmentController) to a specific grader/transporter, charging
 * them the same way in-transit milk loss is already charged in
 * MilkTransferReceptionController. The write-off itself (the accounting
 * entry) happens regardless of this — this only adds accountability on top,
 * and only when the office explicitly chooses to.
 */
class FarmersSpillageController extends Controller
{
    public function chargeGrader(Request $request, int $adjustmentId): JsonResponse
    {
        $data = $request->validate([
            'grader_id' => 'required|integer|exists:inventory_locations,id',
        ]);

        $adjustment = InventoryAdjustment::with('items')->find($adjustmentId);
        if (!$adjustment) {
            return ApiResponse::notFound('Adjustment not found');
        }
        if ($adjustment->status !== 'processed') {
            return ApiResponse::error('Adjustment must be processed before it can be charged to a grader', 422);
        }

        if (DB::table('grader_deductions')
            ->where('source_type', 'milk_spillage')
            ->where('source_ref', $adjustment->reference)
            ->exists()
        ) {
            return ApiResponse::error('This spillage has already been charged to a grader', 422);
        }

        $rawMilkId = $this->rawMilkStockId();
        $lossLine  = $adjustment->items->first(
            fn ($i) => $i->stock_id === $rawMilkId && (float) $i->quantity < 0
        );

        if (!$lossLine) {
            return ApiResponse::error('This adjustment has no RAW MILK loss line to charge', 422);
        }

        $qty  = abs((float) $lossLine->quantity);
        $rate = $this->farmerRateFor($adjustment->date);
        $amount = round($qty * $rate, 2);

        if ($amount <= 0) {
            return ApiResponse::error('Could not determine a farmer rate to value this loss — check recent RAW MILK prices', 422);
        }

        $deduction = GraderDeduction::create([
            'grader_id'      => $data['grader_id'],
            'deduction_date' => $adjustment->date,
            'amount'         => $amount,
            'reason'         => "Milk spillage — {$adjustment->reference}: {$qty} L "
                . '(' . ($adjustment->reason?->label ?? 'Spillage') . ')'
                . ($adjustment->memo ? " — {$adjustment->memo}" : ''),
            'source_type'    => 'milk_spillage',
            'source_ref'     => $adjustment->reference,
            'created_by'     => $request->user()?->real_name ?? $request->user()?->user_id ?? 'system',
        ]);

        return ApiResponse::created($deduction, 'Grader charged for spillage');
    }

    private function rawMilkStockId(): string
    {
        return DB::table('items')
            ->where(fn ($q) => $q
                ->where('long_description', 'like', '%RAW MILK%')
                ->orWhere('description', 'like', '%RAW MILK%')
            )
            ->value('stock_id') ?? '0001';
    }

    private function farmerRateFor(string $date): float
    {
        $rate = DB::table('milk_purchase_items as i')
            ->join('milk_purchases as p', 'p.id', '=', 'i.purchase_id')
            ->whereBetween('p.invoice_date', [date('Y-m-d', strtotime($date . ' -7 days')), $date])
            ->where('i.quality_status', '!=', 'rejected')
            ->where('i.unit_price', '>', 0)
            ->avg('i.unit_price');

        return round((float) ($rate ?? 0), 4);
    }
}
