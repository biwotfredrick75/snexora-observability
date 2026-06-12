<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WeighbridgeController extends Controller
{
    // ── List recent readings ──────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('weighbridge_readings')
            ->orderByDesc('weighed_at');

        if ($request->filled('stock_id'))  $query->where('stock_id', $request->stock_id);
        if ($request->filled('vehicle_no')) $query->where('vehicle_no', 'like', '%' . $request->vehicle_no . '%');
        if ($request->filled('from'))      $query->where('weighed_at', '>=', $request->from . ' 00:00:00');
        if ($request->filled('to'))        $query->where('weighed_at', '<=', $request->to . ' 23:59:59');
        if ($request->boolean('unapplied')) $query->where('applied', false);

        $rows = $query->limit(200)->get();
        return ApiResponse::success($rows, 'Weighbridge readings');
    }

    // ── Save a new reading ────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicle_no'     => 'nullable|string|max:30',
            'supplier_code'  => 'nullable|string|max:30',
            'supplier_name'  => 'nullable|string|max:200',
            'stock_id'       => 'nullable|string|max:30',
            'description'    => 'nullable|string|max:200',
            'unit'           => 'nullable|string|max:20',
            'gross_weight'   => 'required|numeric|min:0',
            'tare_weight'    => 'required|numeric|min:0',
            'source'         => 'nullable|in:manual,scale,imported',
            'trans_type'     => 'nullable|in:grn,work_order,stock_movement,sales,none',
            'trans_ref'      => 'nullable|string|max:50',
            'trans_id'       => 'nullable|integer',
            'weighed_at'     => 'nullable|date',
            'notes'          => 'nullable|string|max:500',
        ]);

        $ticketNo = 'WB-' . now()->format('ymd') . '-' . str_pad(
            DB::table('weighbridge_readings')->whereDate('created_at', today())->count() + 1,
            4, '0', STR_PAD_LEFT
        );

        $id = DB::table('weighbridge_readings')->insertGetId([
            'ticket_no'     => $ticketNo,
            'vehicle_no'    => $data['vehicle_no'] ?? null,
            'supplier_code' => $data['supplier_code'] ?? null,
            'supplier_name' => $data['supplier_name'] ?? null,
            'stock_id'      => $data['stock_id'] ?? null,
            'description'   => $data['description'] ?? null,
            'unit'          => $data['unit'] ?? 'Kgs',
            'gross_weight'  => (float) $data['gross_weight'],
            'tare_weight'   => (float) $data['tare_weight'],
            'source'        => $data['source'] ?? 'manual',
            'trans_type'    => $data['trans_type'] ?? 'none',
            'trans_ref'     => $data['trans_ref'] ?? null,
            'trans_id'      => $data['trans_id'] ?? null,
            'applied'       => false,
            'weighed_at'    => isset($data['weighed_at']) ? \Carbon\Carbon::parse($data['weighed_at'])->toDateTimeString() : now()->toDateTimeString(),
            'operator'      => Auth::user()?->user_id ?? '',
            'notes'         => $data['notes'] ?? null,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $reading = DB::table('weighbridge_readings')->find($id);
        return ApiResponse::created($reading, "Weighbridge ticket {$ticketNo} saved");
    }

    // ── Apply net weight to a linked transaction ──────────────────────────────
    public function apply(Request $request, int $id): JsonResponse
    {
        $reading = DB::table('weighbridge_readings')->findOrFail($id);

        if ($reading->applied) {
            return ApiResponse::error('This reading has already been applied.', 422);
        }

        $data = $request->validate([
            'trans_type' => 'required|in:grn,work_order,stock_movement',
            'trans_id'   => 'required|integer',
        ]);

        $netWeight = (float) $reading->net_weight;

        DB::transaction(function () use ($reading, $data, $netWeight) {
            match ($data['trans_type']) {
                'work_order' => DB::table('work_orders')
                    ->where('id', $data['trans_id'])
                    ->whereIn('status', ['released', 'in_progress'])
                    ->update(['actual_qty_produced' => $netWeight, 'updated_at' => now()]),

                'grn' => DB::table('grn_batch')
                    ->where('id', $data['trans_id'])
                    ->update(['quantity' => $netWeight, 'updated_at' => now()]),

                default => null,
            };

            DB::table('weighbridge_readings')->where('id', $reading->id)->update([
                'trans_type' => $data['trans_type'],
                'trans_id'   => $data['trans_id'],
                'applied'    => true,
                'updated_at' => now(),
            ]);
        });

        return ApiResponse::success(
            DB::table('weighbridge_readings')->find($id),
            "Net weight {$netWeight} applied to {$data['trans_type']} #{$data['trans_id']}"
        );
    }

    // ── Read live weight from a local scale bridge service ────────────────────
    // The scale bridge is a small local HTTP service on the operator's PC
    // (e.g. Node.js or Python) that reads the RS232 serial port and
    // exposes GET http://localhost:3456/weight → { "weight": 12340.5, "unit": "kg", "stable": true }
    public function readScale(Request $request): JsonResponse
    {
        $bridgeUrl = $request->get('bridge_url', 'http://localhost:3456/weight');

        try {
            $ch = curl_init($bridgeUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);
            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($body === false || $httpCode !== 200) {
                return ApiResponse::error('Scale bridge not reachable. Check the bridge service is running.', 503);
            }

            $payload = json_decode($body, true);
            if (!isset($payload['weight'])) {
                return ApiResponse::error('Unexpected response from scale bridge.', 502);
            }

            return ApiResponse::success([
                'weight' => (float) $payload['weight'],
                'unit'   => $payload['unit'] ?? 'kg',
                'stable' => (bool) ($payload['stable'] ?? true),
            ], 'Weight read from scale');
        } catch (\Throwable $e) {
            return ApiResponse::error('Scale bridge error: ' . $e->getMessage(), 503);
        }
    }

    // ── Ticket stats for today ────────────────────────────────────────────────
    public function stats(): JsonResponse
    {
        $today = DB::table('weighbridge_readings')->whereDate('weighed_at', today());

        return ApiResponse::success([
            'today_count'      => $today->count(),
            'today_net_total'  => $today->sum('net_weight'),
            'unapplied_count'  => DB::table('weighbridge_readings')->where('applied', false)->count(),
        ], 'Weighbridge stats');
    }
}
