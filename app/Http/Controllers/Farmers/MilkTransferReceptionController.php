<?php

namespace App\Http\Controllers\Farmers;

use App\Events\DashboardEvent;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\GraderDeduction;
use App\Models\MilkLocationTransfer;
use App\Models\MilkQualitySetting;
use App\Models\MilkTransferReception;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles a transporter's trip (one or more MilkLocationTransfer dispatch
 * legs sharing a trip_ref) when it arrives at the cooling store: re-weigh,
 * re-test quality, split into accepted/rejected/shortage, post the actual
 * stock receipt (accepted_qty only), and — if there's a loss — charge the
 * transporter/grader via a GraderDeduction row that flows into
 * GraderPayrollController's net-pay calculation.
 */
class MilkTransferReceptionController extends Controller
{
    // ── Trips dispatched but not yet received ─────────────────────────────────
    public function pendingTrips(Request $request): JsonResponse
    {
        $query = MilkLocationTransfer::with(['fromLocation', 'toLocation', 'transporter'])
            ->where('status', 'dispatched')
            ->whereNotNull('trip_ref');

        if ($request->filled('to_location_id')) {
            $query->where('to_location_id', (int) $request->to_location_id);
        }

        $legs = $query->orderBy('trip_ref')->get();

        $trips = $legs->groupBy('trip_ref')->map(function ($group) {
            $first = $group->first();

            return [
                'trip_ref'       => $first->trip_ref,
                'to_location'    => $first->toLocation,
                'transporter'    => $first->transporter,
                'transfer_date'  => $first->transfer_date,
                'dispatched_qty' => round((float) $group->sum('quantity'), 3),
                'legs'           => $group->map(fn ($leg) => [
                    'id'            => $leg->id,
                    'from_location' => $leg->fromLocation,
                    'quantity'      => $leg->quantity,
                    'transfer_date' => $leg->transfer_date,
                ])->values(),
            ];
        })->values();

        return ApiResponse::success($trips, 'Pending trips retrieved');
    }

    // ── History ────────────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $query = MilkTransferReception::with(['toLocation', 'transporter'])
            ->orderByDesc('received_at')
            ->orderByDesc('id');

        if ($request->filled('to_location_id')) {
            $query->where('to_location_id', (int) $request->to_location_id);
        }

        return ApiResponse::success($query->limit(200)->get(), 'Receptions retrieved');
    }

    public function show(int $id): JsonResponse
    {
        $reception = MilkTransferReception::with(['toLocation', 'transporter'])->findOrFail($id);

        return ApiResponse::success($reception, 'Reception retrieved');
    }

    // ── Confirm reception for a trip ──────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'trip_ref'             => 'required|string|max:30',
            'received_qty'         => 'nullable|numeric|min:0',
            'gross_weight'         => 'nullable|numeric|min:0',
            'tare_weight'          => 'nullable|numeric|min:0',
            'temperature_c'        => 'nullable|numeric|min:-5|max:15',
            'smell_result'         => 'nullable|in:normal,abnormal',
            'alcohol_test_result'  => 'nullable|in:negative,positive',
            'density'              => 'nullable|numeric|min:0.9|max:1.2',
            'butterfat_percent'    => 'nullable|numeric|min:0|max:100',
            'adulteration_result'  => 'nullable|in:negative,positive',
            // Shown to the office/grader as a computed suggestion — this
            // lets them adjust it before it's posted, rather than silently
            // trusting an automatic valuation.
            'deduction_amount'     => 'nullable|numeric|min:0',
        ]);

        $legs = MilkLocationTransfer::where('trip_ref', $data['trip_ref'])
            ->where('status', 'dispatched')
            ->get();

        if ($legs->isEmpty()) {
            return ApiResponse::error("No dispatched legs found for trip {$data['trip_ref']} — it may already be received.", 422);
        }

        $first        = $legs->first();
        $toLocation   = $first->toLocation;
        $transporterId= $first->transporter_id;
        $dispatchedQty= (float) $legs->sum('quantity');

        $grossWeight = isset($data['gross_weight']) ? (float) $data['gross_weight'] : null;
        $tareWeight  = isset($data['tare_weight']) ? (float) $data['tare_weight'] : null;
        $netWeight   = ($grossWeight !== null && $tareWeight !== null) ? $grossWeight - $tareWeight : null;
        $receivedQty = $netWeight ?? (isset($data['received_qty']) ? (float) $data['received_qty'] : null);

        if ($receivedQty === null) {
            return ApiResponse::validationError([
                'received_qty' => ['Provide either received_qty or gross_weight + tare_weight.'],
            ]);
        }

        // ── Quality retest — same threshold logic used at initial
        // collection (MilkPurchaseController::store()'s evaluateQuality). ──
        $qc = MilkQualitySetting::current();
        $tested = isset($data['smell_result']) || isset($data['alcohol_test_result'])
            || isset($data['density']) || isset($data['butterfat_percent'])
            || isset($data['adulteration_result']);

        $qualityStatus   = 'accepted';
        $rejectionReason = null;

        if ($tested) {
            $failures = [];
            if ($qc->reject_on_alcohol_positive && ($data['alcohol_test_result'] ?? null) === 'positive') {
                $failures[] = 'alcohol test positive';
            }
            if ($qc->reject_on_adulteration_positive && ($data['adulteration_result'] ?? null) === 'positive') {
                $failures[] = 'adulteration detected';
            }
            if ($qc->reject_on_abnormal_smell && ($data['smell_result'] ?? null) === 'abnormal') {
                $failures[] = 'abnormal smell';
            }
            if (isset($data['butterfat_percent']) && (float) $data['butterfat_percent'] < $qc->min_butterfat_percent) {
                $failures[] = 'butterfat below ' . $qc->min_butterfat_percent . '%';
            }
            if (isset($data['density'])) {
                $d = (float) $data['density'];
                if ($d < $qc->min_density || $d > $qc->max_density) {
                    $failures[] = 'density out of range (' . $qc->min_density . '-' . $qc->max_density . ')';
                }
            }
            if (! empty($failures)) {
                $qualityStatus   = 'rejected';
                $rejectionReason = implode('; ', $failures);
            }
        }

        $shortageQty = max(0, round($dispatchedQty - $receivedQty, 3));
        $rejectedQty = $qualityStatus === 'rejected' ? $receivedQty : 0.0;
        $acceptedQty = max(0, round($receivedQty - $rejectedQty, 3));

        $lossQty          = round($shortageQty + $rejectedQty, 3);
        $suggestedRate     = $this->averageRawMilkRate($first->transfer_date->toDateString());
        $deductionAmount  = $data['deduction_amount'] ?? round($lossQty * $suggestedRate, 2);

        $user = auth()->user()?->user_id ?? 'system';

        DB::beginTransaction();
        try {
            $reception = MilkTransferReception::create([
                'trip_ref'              => $data['trip_ref'],
                'to_location_id'        => $first->to_location_id,
                'transporter_id'        => $transporterId,
                'dispatched_qty'        => $dispatchedQty,
                'received_gross_weight' => $grossWeight,
                'received_tare_weight'  => $tareWeight,
                'received_net_weight'   => $netWeight,
                'temperature_c'         => $data['temperature_c'] ?? null,
                'smell_result'          => $data['smell_result'] ?? null,
                'alcohol_test_result'   => $data['alcohol_test_result'] ?? null,
                'density'               => $data['density'] ?? null,
                'butterfat_percent'     => $data['butterfat_percent'] ?? null,
                'adulteration_result'   => $data['adulteration_result'] ?? null,
                'quality_status'        => $qualityStatus,
                'rejection_reason'      => $rejectionReason,
                'received_qty'          => $receivedQty,
                'accepted_qty'          => $acceptedQty,
                'rejected_qty'          => $rejectedQty,
                'shortage_qty'          => $shortageQty,
                'deduction_amount'      => $lossQty > 0 ? $deductionAmount : 0,
                'received_by'           => $user,
                'received_at'           => now(),
            ]);

            if ($acceptedQty > 0) {
                $transNo   = (int) DB::table('stock_movements')->max('trans_no') + 1;
                $reference = 'RCV-' . str_pad($transNo, 5, '0', STR_PAD_LEFT);

                DB::table('stock_movements')->insert([
                    'trans_no'      => $transNo,
                    'stock_id'      => $this->rawMilkStockId(),
                    'type'          => StockMovement::TYPE_TRANSFER,
                    'tran_date'     => now()->toDateString(),
                    'date_moved'    => now()->toDateString(),
                    'reference'     => $reference,
                    'comments'      => "Cooling store reception — trip {$data['trip_ref']}",
                    'user_name'     => $user,
                    'approved'      => 1,
                    'loc_code'      => $toLocation->code,
                    'loc_code_from' => null,
                    'qty'           => $acceptedQty,
                ]);
            }

            if ($lossQty > 0 && $transporterId && $reception->deduction_amount > 0) {
                GraderDeduction::create([
                    'grader_id'      => $transporterId,
                    'deduction_date' => now()->toDateString(),
                    'amount'         => $reception->deduction_amount,
                    'reason'         => "Milk shortage/rejection in transit — trip {$data['trip_ref']}: "
                        . round($lossQty, 2) . ' L ('
                        . ($shortageQty > 0 ? round($shortageQty, 2) . ' L short' : '')
                        . ($shortageQty > 0 && $rejectedQty > 0 ? ', ' : '')
                        . ($rejectedQty > 0 ? round($rejectedQty, 2) . ' L rejected: ' . $rejectionReason : '')
                        . ')',
                    'source_type'    => 'milk_transport_loss',
                    'source_ref'     => $data['trip_ref'],
                    'created_by'     => $user,
                ]);
            }

            $legs->each->update(['status' => 'received']);

            DB::commit();

            try {
                broadcast(new DashboardEvent('milk_purchase', 'received', [
                    'trip_ref' => $data['trip_ref'],
                    'accepted' => $acceptedQty,
                    'shortage' => $shortageQty,
                    'rejected' => $rejectedQty,
                ]));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Dashboard broadcast failed: ' . $e->getMessage());
            }

            return ApiResponse::created(
                $reception->fresh()->load(['toLocation', 'transporter']),
                "Trip {$data['trip_ref']} received — {$acceptedQty} L accepted"
                . ($lossQty > 0 ? ", {$lossQty} L lost (charged to transporter)" : '')
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Average raw-milk unit cost over the trailing 7 days up to $date —
     *  same fallback style as MilkPurchaseController's price resolution —
     *  used to value the transporter's shortage/rejection charge. */
    private function averageRawMilkRate(string $date): float
    {
        $rate = DB::table('milk_purchase_items as i')
            ->join('milk_purchases as p', 'p.id', '=', 'i.purchase_id')
            ->whereBetween('p.invoice_date', [date('Y-m-d', strtotime($date . ' -7 days')), $date])
            ->where('i.unit_price', '>', 0)
            ->avg('i.unit_price');

        return round((float) ($rate ?? 0), 4);
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
}
