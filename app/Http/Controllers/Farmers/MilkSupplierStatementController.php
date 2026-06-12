<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MilkSupplierStatementController extends Controller
{
    public function formData(): JsonResponse
    {
        $farmers = DB::table('farmers')
            ->orderBy('farmer_no')
            ->get(['id', 'farmer_no', 'full_name']);

        return ApiResponse::success(['farmers' => $farmers], 'OK');
    }

    public function statement(Request $request): JsonResponse
    {
        $request->validate([
            'farmer_id' => 'required|integer|exists:farmers,id',
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
        ]);

        // Farmer details
        $farmer = DB::table('farmers as f')
            ->leftJoin('farmer_banks as b', 'b.id', '=', 'f.bank_id')
            ->leftJoin('milk_routes as r', 'r.id', '=', 'f.route_id')
            ->where('f.id', $request->farmer_id)
            ->select('f.id', 'f.farmer_no', 'f.full_name', 'f.id_no', 'f.phone',
                     'f.account_no', DB::raw('b.name as bank_name'),
                     DB::raw('r.route_name as route_name'))
            ->first();

        // Purchase items grouped by date and shift category
        $purchases = DB::table('milk_purchase_items as mpi')
            ->join('milk_purchases as mp', 'mp.id', '=', 'mpi.purchase_id')
            ->leftJoin('milk_collection_shifts as s', 's.id', '=', 'mp.shift_id')
            ->where('mpi.farmer_id', $request->farmer_id)
            ->where('mp.status', 'approved')
            ->whereBetween('mp.invoice_date', [$request->date_from, $request->date_to])
            ->select([
                'mp.invoice_date as date',
                'mp.due_date',
                DB::raw("CASE
                    WHEN LOWER(s.description) LIKE '%morning%' OR LOWER(s.description) LIKE '%day%'
                    THEN 'morning' ELSE 'evening'
                END as shift_cat"),
                DB::raw('SUM(mpi.quantity) as qty'),
                DB::raw('AVG(mpi.unit_price) as price'),
                DB::raw('SUM(mpi.total_price) as amount'),
            ])
            ->groupBy('mp.invoice_date', 'mp.due_date', 'shift_cat')
            ->get()
            ->groupBy('date');

        // Build daily rows over the full date range
        $start    = Carbon::parse($request->date_from);
        $end      = Carbon::parse($request->date_to);
        $rows     = [];
        $running  = 0;
        $totMorn  = 0;
        $totEven  = 0;
        $totQty   = 0;
        $totAmt   = 0;

        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $dateStr = $d->format('Y-m-d');
            $day     = $purchases->get($dateStr, collect());

            $morn = $day->firstWhere('shift_cat', 'morning');
            $even = $day->firstWhere('shift_cat', 'evening');

            $mornQty   = (float) ($morn->qty   ?? 0);
            $mornPrice = (float) ($morn->price  ?? 0);
            $evenQty   = (float) ($even->qty    ?? 0);
            $evenPrice = (float) ($even->price  ?? 0);
            $totalQty  = $mornQty + $evenQty;

            // Use morning price for total if available, else evening
            $price    = $mornPrice ?: $evenPrice;
            $mornAmt  = round($mornQty * $mornPrice, 2);
            $evenAmt  = round($evenQty * $evenPrice, 2);
            $dayAmt   = $mornAmt + $evenAmt;
            $running += $dayAmt;

            $dueDate  = $morn->due_date ?? $even->due_date ?? null;

            $rows[] = [
                'date'        => $dateStr,
                'due_date'    => $dueDate,
                'morning_qty'   => $mornQty,
                'morning_price' => $mornPrice,
                'evening_qty'   => $evenQty,
                'evening_price' => $evenPrice,
                'total_qty'     => $totalQty,
                'amount'        => $dayAmt,
                'due_amount'    => $running,
                'has_data'      => $totalQty > 0,
            ];

            $totMorn += $mornQty;
            $totEven += $evenQty;
            $totQty  += $totalQty;
            $totAmt  += $dayAmt;
        }

        return ApiResponse::success([
            'farmer' => $farmer,
            'rows'   => $rows,
            'totals' => [
                'total_morning_qty' => $totMorn,
                'total_evening_qty' => $totEven,
                'total_qty'         => $totQty,
                'total_amount'      => $totAmt,
                'due_amount'        => $running,
            ],
        ], 'OK');
    }
}
