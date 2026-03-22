<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\InventoryLocation;
use App\Models\MilkCollectionShift;
use App\Models\MilkLocationTransfer;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MilkLocationTransferController extends Controller
{
    private const RAW_MILK_STOCK_ID = '0001';

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

        $query = DB::table('stock_movements')
            ->where('stock_id', self::RAW_MILK_STOCK_ID)
            ->where('loc_code', $location->code)
            ->where('tran_date', $request->transfer_date);

        if ($shiftLabel) {
            $query->where('shift', $shiftLabel);
        }

        $availableQty = (float) $query->sum('qty');

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
        $transfers = MilkLocationTransfer::with(['fromLocation', 'toLocation', 'shift'])
            ->orderByDesc('transfer_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

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
        ]);

        $fromLocation = InventoryLocation::findOrFail($data['from_location_id']);
        $toLocation   = InventoryLocation::findOrFail($data['to_location_id']);
        $shiftLabel   = $this->shiftLabel($data['shift_id'] ?? null);
        $user         = auth()->user()?->user_id ?? 'system';
        $qty          = (float) $data['quantity'];

        DB::beginTransaction();
        try {
            // Persist transfer record
            $transfer = MilkLocationTransfer::create([
                'from_location_id' => $data['from_location_id'],
                'to_location_id'   => $data['to_location_id'],
                'transfer_date'    => $data['transfer_date'],
                'shift_id'         => $data['shift_id'] ?? null,
                'quantity'         => $qty,
                'quality_notes'    => $data['quality_notes'],
                'status'           => 'processed',
                'created_by'       => $user,
            ]);

            // Generate trans_no and reference
            $transNo   = (int) DB::table('stock_movements')->max('trans_no') + 1;
            $reference = 'TRF-' . str_pad($transNo, 5, '0', STR_PAD_LEFT);
            $today     = now()->toDateString();

            $base = [
                'trans_no'  => $transNo,
                'stock_id'  => self::RAW_MILK_STOCK_ID,
                'type'      => StockMovement::TYPE_TRANSFER,
                'tran_date' => $data['transfer_date'],
                'date_moved'=> $today,
                'reference' => $reference,
                'shift'     => $shiftLabel,
                'comments'  => $data['quality_notes'],
                'user_name' => $user,
                'approved'  => 1,
            ];

            // Outgoing: subtract from source
            DB::table('stock_movements')->insert(array_merge($base, [
                'loc_code'      => $fromLocation->code,
                'loc_code_from' => null,
                'qty'           => -$qty,
            ]));

            // Incoming: add to destination
            DB::table('stock_movements')->insert(array_merge($base, [
                'loc_code'      => $toLocation->code,
                'loc_code_from' => $fromLocation->code,
                'qty'           => $qty,
            ]));

            DB::commit();

            return ApiResponse::created(
                $transfer->fresh()->load(['fromLocation', 'toLocation', 'shift']),
                'Transfer processed'
            );
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // ── Helper ───────────────────────────────────────────────────────────────
    private function shiftLabel(?int $shiftId): string
    {
        if (!$shiftId) return '';
        $shift = MilkCollectionShift::find($shiftId);
        return $shift ? strtoupper($shift->description) : '';
    }
}
