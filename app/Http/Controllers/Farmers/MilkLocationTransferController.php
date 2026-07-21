<?php

namespace App\Http\Controllers\Farmers;

use App\Events\DashboardEvent;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\GraderDeduction;
use App\Models\InventoryLocation;
use App\Models\MilkCollectionShift;
use App\Models\MilkLocationTransfer;
use App\Models\MilkQualitySetting;
use App\Models\MilkTransferReception;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MilkLocationTransferController extends Controller
{
    // ── Form dropdowns ───────────────────────────────────────────────────────
    public function formData(): JsonResponse
    {
        return ApiResponse::success([
            'locations' => InventoryLocation::where('inactive', false)
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'shifts' => MilkCollectionShift::where('active', true)
                ->orderBy('description')
                ->get(['id', 'description']),
            // Transporters = graders (they're the ones who haul the milk —
            // same party_type='transporter' convention used in
            // esp_farmer_sales / EspController::indexParties).
            'transporters' => InventoryLocation::whereIn('type', ['grader', 'vendor'])
                ->where('inactive', false)
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
        ], 'Form data retrieved');
    }

    // ── Available raw-milk quantity at a location for a given date/shift ─────
    public function availableQuantity(Request $request): JsonResponse
    {
        $request->validate([
            'from_location_id' => 'required|integer|exists:inventory_locations,id',
            'transfer_date'    => 'required|date',
            'shift_id'         => 'nullable|integer|exists:milk_collection_shifts,id',
        ]);

        $location = InventoryLocation::findOrFail($request->from_location_id);

        $shiftLabel = $this->shiftLabel($request->shift_id);

        $availableQty = $this->availableQtyAt($location->code, $request->transfer_date, $shiftLabel);

        return ApiResponse::success([
            'available_qty' => round($availableQty, 3),
            'location_name' => $location->name,
            'location_code' => $location->code,
            'shift'         => $shiftLabel,
        ], 'Available quantity retrieved');
    }

    // ── List transfers ───────────────────────────────────────────────────────
    public function index(): JsonResponse
    {
        $query = MilkLocationTransfer::with(['fromLocation', 'toLocation', 'shift', 'transporter'])
            ->orderByDesc('transfer_date')
            ->orderByDesc('id');

        // Graders only see their own transfers — scoped by created_by, the
        // same auth()-derived field store() always sets. Same reasoning as
        // MilkPurchaseController::index(): scoping by a location/store-code
        // lookup instead would silently show nothing for any grader account
        // whose default_store doesn't map to a real inventory_locations row
        // (exactly what broke milk purchases for this account before).
        // Web/office users (no grader role) keep the unscoped, all-transfers
        // view this endpoint already served them.
        if (auth()->user()?->hasRole('grader')) {
            $query->where('created_by', auth()->user()->user_id);
        }

        $transfers = $query->limit(200)->get();

        return ApiResponse::success($transfers, 'Transfers retrieved');
    }

    // ── Create transfer + write stock movements ──────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_location_id' => 'required|integer|exists:inventory_locations,id',
            'to_location_id'   => [
                'required', 'integer', 'exists:inventory_locations,id',
                'different:from_location_id',
            ],
            'transfer_date'  => ['required', 'date', new \App\Rules\WithinFiscalYear],
            'shift_id'       => 'nullable|integer|exists:milk_collection_shifts,id',
            'quantity'       => 'required|numeric|min:0.001',
            'quality_notes'  => 'required|string|max:1000',
            // Groups this leg with other collection-point pickups made by the
            // same transporter run — the app generates one on the first leg
            // and resends it on every subsequent leg of the same trip.
            'trip_ref'       => 'nullable|string|max:30',
            'transporter_id' => 'nullable|integer|exists:inventory_locations,id',
            // Dispatch-side weighing (BLE/classic scale in the app, or
            // manual entry as a fallback) — net weight, if given, is what's
            // actually posted as `quantity` below.
            'gross_weight'   => 'nullable|numeric|min:0',
            'tare_weight'    => 'nullable|numeric|min:0',
            // Handover quality check — same shape/thresholds as initial
            // collection (MilkPurchaseController::store()'s evaluateQuality)
            // — captured here, at the point of transfer, instead of a
            // separate reception step.
            'temperature_c'       => 'nullable|numeric|min:-5|max:15',
            'smell_result'        => 'nullable|in:normal,abnormal',
            'alcohol_test_result' => 'nullable|in:negative,positive',
            'density'             => 'nullable|numeric|min:0.9|max:1.2',
            'butterfat_percent'   => 'nullable|numeric|min:0|max:100',
            'adulteration_result' => 'nullable|in:negative,positive',
        ]);

        $fromLocation = InventoryLocation::findOrFail($data['from_location_id']);
        $toLocation   = InventoryLocation::findOrFail($data['to_location_id']);
        $shiftLabel   = $this->shiftLabel($data['shift_id'] ?? null);
        $user         = auth()->user()?->user_id ?? 'system';

        $grossWeight = isset($data['gross_weight']) ? (float) $data['gross_weight'] : null;
        $tareWeight  = isset($data['tare_weight']) ? (float) $data['tare_weight'] : null;
        $netWeight   = ($grossWeight !== null && $tareWeight !== null) ? $grossWeight - $tareWeight : null;
        $qty         = $netWeight ?? (float) $data['quantity'];

        // Transporter is the grader themselves — trust an explicit value
        // from the app (it already knows its own logged-in grader's
        // location), but fall back to resolving the authenticated user's
        // own station the same way GraderAuthController does, so manual/web
        // entries and older app builds still work.
        $transporterId = $data['transporter_id'] ?? null;
        if (! $transporterId) {
            $defaultStore = auth()->user()?->default_store;
            if ($defaultStore) {
                $transporterId = InventoryLocation::where('code', $defaultStore)->value('id');
            }
        }

        $tripRef = $data['trip_ref'] ?? $this->nextTripRef();

        // Never trust the client's own "available" figure (shown to the user
        // as a convenience, computed moments earlier) — re-check server-side
        // right before writing, so a stale screen or a second concurrent
        // transfer can't push the source location negative.
        $availableQty = $this->availableQtyAt($fromLocation->code, $data['transfer_date'], $shiftLabel);
        if ($qty > $availableQty + 0.0005) {
            return ApiResponse::validationError([
                'quantity' => [
                    "Only " . round($availableQty, 3) . " L available at {$fromLocation->name} for this date/shift.",
                ],
            ]);
        }

        DB::beginTransaction();
        try {
            // Persist transfer record — quality is checked and the
            // destination credited right here, in the same request, rather
            // than waiting on a separate reception step.
            $transfer = MilkLocationTransfer::create([
                'trip_ref'              => $tripRef,
                'from_location_id'      => $data['from_location_id'],
                'to_location_id'        => $data['to_location_id'],
                'transporter_id'        => $transporterId,
                'transfer_date'         => $data['transfer_date'],
                'shift_id'              => $data['shift_id'] ?? null,
                'quantity'              => $qty,
                'dispatch_gross_weight' => $grossWeight,
                'dispatch_tare_weight'  => $tareWeight,
                'dispatch_net_weight'   => $netWeight,
                'quality_notes'         => $data['quality_notes'],
                'status'                => 'received',
                'created_by'            => $user,
            ]);

            // Generate trans_no and reference
            $transNo   = (int) DB::table('stock_movements')->max('trans_no') + 1;
            $reference = 'TRF-' . str_pad($transNo, 5, '0', STR_PAD_LEFT);
            $today     = now()->toDateString();

            // Outgoing: subtract the full quantity from the source.
            DB::table('stock_movements')->insert([
                'trans_no'      => $transNo,
                'stock_id'      => $this->rawMilkStockId(),
                'type'          => StockMovement::TYPE_TRANSFER,
                'tran_date'     => $data['transfer_date'],
                'date_moved'    => $today,
                'reference'     => $reference,
                'shift'         => $shiftLabel,
                'comments'      => $data['quality_notes'],
                'user_name'     => $user,
                'approved'      => 1,
                'loc_code'      => $fromLocation->code,
                'loc_code_from' => null,
                'qty'           => -$qty,
            ]);

            // Quality check at handover — same threshold logic used at
            // initial collection (MilkPurchaseController::store()'s
            // evaluateQuality). Only the accepted portion is credited to
            // the destination; a rejected load is charged to the
            // transporter/grader via GraderDeduction.
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

            $rejectedQty = $qualityStatus === 'rejected' ? $qty : 0.0;
            $acceptedQty = max(0, round($qty - $rejectedQty, 3));
            $deductionAmount = 0.0;

            // Incoming: credit the destination now, accepted_qty only.
            if ($acceptedQty > 0) {
                DB::table('stock_movements')->insert([
                    'trans_no'      => (int) DB::table('stock_movements')->max('trans_no') + 1,
                    'stock_id'      => $this->rawMilkStockId(),
                    'type'          => StockMovement::TYPE_TRANSFER,
                    'tran_date'     => $data['transfer_date'],
                    'date_moved'    => $today,
                    'reference'     => 'RCV-' . str_pad($transNo, 5, '0', STR_PAD_LEFT),
                    'comments'      => "Transfer receipt — trip {$tripRef}",
                    'user_name'     => $user,
                    'approved'      => 1,
                    'loc_code'      => $toLocation->code,
                    'loc_code_from' => null,
                    'qty'           => $acceptedQty,
                ]);
            }

            if ($rejectedQty > 0) {
                // Physical write-off: rejected milk doesn't cease to exist —
                // it was already deducted from the source above (the truck
                // left with it), so without this it just vanishes from
                // system-wide stock with no trace besides the financial
                // GraderDeduction below. Credit it to the Returns Store
                // (the existing returns_store=1 location — same convention
                // used elsewhere for rejected/returned goods) so total
                // stock still reconciles: farmers supplied = accepted at
                // destination + rejected/returns + whatever's on hand.
                $returnsLocation = InventoryLocation::where('returns_store', true)
                    ->where('inactive', false)->first();
                if ($returnsLocation) {
                    DB::table('stock_movements')->insert([
                        'trans_no'      => (int) DB::table('stock_movements')->max('trans_no') + 1,
                        'stock_id'      => $this->rawMilkStockId(),
                        'type'          => StockMovement::TYPE_TRANSFER,
                        'tran_date'     => $data['transfer_date'],
                        'date_moved'    => $today,
                        'reference'     => 'REJ-' . str_pad($transNo, 5, '0', STR_PAD_LEFT),
                        'comments'      => "Rejected in transfer — trip {$tripRef}: {$rejectionReason}",
                        'user_name'     => $user,
                        'approved'      => 1,
                        'loc_code'      => $returnsLocation->code,
                        'loc_code_from' => null,
                        'qty'           => $rejectedQty,
                    ]);
                }

                if ($transporterId) {
                    $deductionAmount = round($rejectedQty * $this->averageRawMilkRate($data['transfer_date']), 2);
                    if ($deductionAmount > 0) {
                        GraderDeduction::create([
                            'grader_id'      => $transporterId,
                            'deduction_date' => $today,
                            'amount'         => $deductionAmount,
                            'reason'         => "Milk rejected in transfer — trip {$tripRef}: "
                                . round($rejectedQty, 2) . " L rejected: {$rejectionReason}",
                            'source_type'    => 'milk_transport_loss',
                            'source_ref'     => $tripRef,
                            'created_by'     => $user,
                        ]);
                    }
                }
            }

            MilkTransferReception::create([
                'trip_ref'              => $tripRef,
                'to_location_id'        => $data['to_location_id'],
                'transporter_id'        => $transporterId,
                'dispatched_qty'        => $qty,
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
                'received_qty'          => $qty,
                'accepted_qty'          => $acceptedQty,
                'rejected_qty'          => $rejectedQty,
                'shortage_qty'          => 0,
                'deduction_amount'      => $deductionAmount,
                'received_by'           => $user,
                'received_at'           => now(),
            ]);

            DB::commit();

            try {
                broadcast(new DashboardEvent('milk_purchase', 'transferred', [
                    'from' => $fromLocation->code, 'to' => $toLocation->code, 'qty' => $acceptedQty,
                ]));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Dashboard broadcast failed: ' . $e->getMessage());
            }

            $result = $transfer->fresh()->load(['fromLocation', 'toLocation', 'shift', 'transporter'])->toArray();
            $result['accepted_qty']     = $acceptedQty;
            $result['rejected_qty']     = $rejectedQty;
            $result['deduction_amount'] = $deductionAmount;

            $message = $rejectedQty > 0
                ? "Transferred {$qty} L — {$acceptedQty} L accepted at {$toLocation->name}, {$rejectedQty} L rejected ({$rejectionReason})"
                : "Transferred {$qty} L to {$toLocation->name}";

            return ApiResponse::created($result, $message);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function nextTripRef(): string
    {
        $prefix = 'TRIP-' . now()->format('Ymd') . '-';
        $count  = MilkLocationTransfer::where('trip_ref', 'like', $prefix . '%')->count();

        return $prefix . str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Net raw-milk qty at a location for a date/shift — shared by
     *  availableQuantity() (read-only check shown in the UI) and store()
     *  (the authoritative check enforced before writing). */
    private function availableQtyAt(string $locCode, string $date, string $shiftLabel): float
    {
        $query = DB::table('stock_movements')
            ->where('stock_id', $this->rawMilkStockId())
            ->where('loc_code', $locCode)
            ->where('tran_date', $date);

        if ($shiftLabel) {
            $query->whereRaw('UPPER(shift) = ?', [$shiftLabel]);
        }

        return (float) $query->sum('qty');
    }

    private function shiftLabel(?int $shiftId): string
    {
        if (!$shiftId) return '';
        $shift = MilkCollectionShift::find($shiftId);
        return $shift ? strtoupper($shift->description) : '';
    }

    /** Average raw-milk unit cost over the trailing 7 days up to $date —
     *  used to value a rejected-at-transfer charge against the transporter. */
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
