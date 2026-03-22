<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockMovementInquiryController extends Controller
{
    // Human-readable labels for movement types
    private const TYPE_LABELS = [
        13 => 'Transfer',
        16 => 'Adjustment',
        17 => 'Requisition',
        18 => 'Consumable',
    ];

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'stock_id'    => 'required|string|max:20',
            'location_id' => 'nullable|string|max:30',
            'from'        => 'nullable|date',
            'to'          => 'nullable|date',
            'batch_no'    => 'nullable|string|max:50',
        ]);

        $stockId  = $request->stock_id;
        $locCode  = $request->location_id;
        $from     = $request->from ?? now()->startOfMonth()->toDateString();
        $to       = $request->to   ?? now()->toDateString();
        $batchNo  = $request->batch_no;

        // ── Opening balance: all movements before $from ───────────────────────
        $openingQuery = StockMovement::where('stock_id', $stockId)
            ->where('tran_date', '<', $from);
        if ($locCode) $openingQuery->where('loc_code', $locCode);
        $openingBalance = (float) $openingQuery->sum('qty');

        // ── Movements in period ───────────────────────────────────────────────
        $query = StockMovement::where('stock_id', $stockId)
            ->whereBetween('tran_date', [$from, $to])
            ->orderBy('tran_date')
            ->orderBy('trans_id');

        if ($locCode)  $query->where('loc_code', $locCode);
        if ($batchNo)  $query->where('batch_no', $batchNo);

        $movements = $query->get([
            'trans_id', 'type', 'trans_no', 'reference',
            'loc_code', 'tran_date', 'qty', 'price', 'batch_no', 'comments',
        ]);

        // Build rows with running balance
        $running   = $openingBalance;
        $totalIn   = 0;
        $totalOut  = 0;

        $rows = $movements->map(function ($m) use (&$running, &$totalIn, &$totalOut) {
            $running += (float) $m->qty;
            $qtyIn   = $m->qty > 0 ? (float) $m->qty : 0;
            $qtyOut  = $m->qty < 0 ? abs((float) $m->qty) : 0;
            $totalIn  += $qtyIn;
            $totalOut += $qtyOut;

            return [
                'trans_id'  => $m->trans_id,
                'type'      => $m->type,
                'type_label'=> self::TYPE_LABELS[$m->type] ?? "Type {$m->type}",
                'trans_no'  => $m->trans_no,
                'reference' => $m->reference,
                'loc_code'  => $m->loc_code,
                'tran_date' => $m->tran_date,
                'detail'    => $m->comments,
                'batch_no'  => $m->batch_no,
                'qty_in'    => $qtyIn,
                'qty_out'   => $qtyOut,
                'balance'   => $running,
            ];
        });

        return ApiResponse::success([
            'stock_id'        => $stockId,
            'from'            => $from,
            'to'              => $to,
            'opening_balance' => $openingBalance,
            'closing_balance' => $running,
            'total_in'        => $totalIn,
            'total_out'       => $totalOut,
            'movements'       => $rows,
        ], 'Movements retrieved');
    }
}
