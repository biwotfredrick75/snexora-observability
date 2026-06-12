<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MilkSupplierReportController extends Controller
{
    public function formData(): JsonResponse
    {
        $farmers = DB::table('farmers')
            ->orderBy('farmer_no')
            ->get(['id', 'farmer_no', 'full_name']);

        return ApiResponse::success(['farmers' => $farmers], 'OK');
    }

    public function report(Request $request): JsonResponse
    {
        $request->validate([
            'farmer_id' => 'required|integer|exists:farmers,id',
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
        ]);

        $farmer = DB::table('farmers')->find($request->farmer_id, ['id', 'farmer_no', 'full_name']);

        $rows = DB::table('milk_purchase_items as mpi')
            ->join('milk_purchases as mp', 'mp.id', '=', 'mpi.purchase_id')
            ->leftJoin('milk_collection_shifts as s', 's.id', '=', 'mp.shift_id')
            ->leftJoin('milk_routes as r', 'r.id', '=', 'mp.route_id')
            ->where('mpi.farmer_id', $request->farmer_id)
            ->where('mp.status', 'approved')
            ->whereBetween('mp.invoice_date', [$request->date_from, $request->date_to])
            ->orderBy('mp.invoice_date')
            ->orderBy('mp.id')
            ->select([
                'mp.invoice_date as date',
                'mp.reference_no',
                DB::raw("COALESCE(s.description, '—') as shift"),
                DB::raw("COALESCE(r.route_name, '—') as route"),
                'mpi.quantity',
                'mpi.unit_price',
                'mpi.total_price',
            ])
            ->get();

        $totals = [
            'total_qty'    => $rows->sum('quantity'),
            'total_amount' => $rows->sum('total_price'),
            'row_count'    => $rows->count(),
        ];

        return ApiResponse::success([
            'farmer' => $farmer,
            'rows'   => $rows,
            'totals' => $totals,
        ], 'OK');
    }
}
