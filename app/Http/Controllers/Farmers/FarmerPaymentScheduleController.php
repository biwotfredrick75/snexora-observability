<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FarmerPaymentScheduleController extends Controller
{
    public function formData(): JsonResponse
    {
        return ApiResponse::success([
            'routes' => DB::table('milk_routes')->orderBy('route_name')->get(['id', 'route_code', 'route_name']),
            'banks'  => DB::table('farmer_banks')->orderBy('name')->get(['id', 'name']),
        ], 'OK');
    }

    public function schedule(Request $request): JsonResponse
    {
        $request->validate([
            'month'    => 'required|integer|min:1|max:12',
            'year'     => 'required|integer|min:2000',
            'route_id' => 'nullable|integer|exists:milk_routes,id',
            'bank_id'  => 'nullable|integer|exists:farmer_banks,id',
        ]);

        $month = (int) $request->month;
        $year  = (int) $request->year;

        $dateFrom = sprintf('%04d-%02d-01', $year, $month);
        $dateTo   = date('Y-m-t', strtotime($dateFrom));

        // ── Gross milk per farmer for the period ──────────────────────────────
        $grossSub = DB::table('milk_purchase_items as mpi')
            ->join('milk_purchases as mp', 'mp.id', '=', 'mpi.purchase_id')
            ->where('mp.status', 'approved')
            ->whereBetween('mp.invoice_date', [$dateFrom, $dateTo])
            ->selectRaw('mpi.farmer_id, SUM(mpi.quantity) AS total_qty, SUM(mpi.total_price) AS gross_amount');
        if ($request->filled('route_id')) {
            $grossSub->where('mp.route_id', $request->route_id);
        }
        $grossSub->groupBy('mpi.farmer_id');

        // ── Join farmers with gross milk ──────────────────────────────────────
        $query = DB::table(DB::raw('(' . $grossSub->toSql() . ') as g'))
            ->mergeBindings($grossSub)
            ->join('farmers as f',        'f.id',  '=', 'g.farmer_id')
            ->leftJoin('farmer_banks as b', 'b.id', '=', 'f.bank_id')
            ->leftJoin('milk_routes as r',  'r.id', '=', 'f.route_id')
            ->selectRaw('
                f.id            AS farmer_id,
                f.farmer_no,
                f.member_no,
                f.full_name,
                f.id_no,
                f.phone,
                f.account_no,
                f.bank_id,
                b.name          AS bank_name,
                r.route_name,
                g.total_qty,
                g.gross_amount
            ')
            ->orderBy('f.farmer_no');

        if ($request->filled('route_id')) {
            $query->where('f.route_id', $request->route_id);
        }
        if ($request->filled('bank_id')) {
            $query->where('f.bank_id', $request->bank_id);
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            return ApiResponse::success([
                'rows' => [], 'totals' => $this->zeroTotals(), 'month' => $month, 'year' => $year,
            ], 'No payment data found');
        }

        $farmerIds = $rows->pluck('farmer_id')->all();

        // ── Checkoff deductions for the period ───────────────────────────────
        $deductMap = DB::table('farmer_checkoff_entries')
            ->whereIn('farmer_id', $farmerIds)
            ->where('month', $month)->where('year', $year)
            ->selectRaw('farmer_id, SUM(amount) AS total_deductions')
            ->groupBy('farmer_id')
            ->pluck('total_deductions', 'farmer_id');

        $result = [];
        $totGross = $totDeduct = $totNet = $totQty = 0;

        foreach ($rows as $row) {
            $gross   = (float) $row->gross_amount;
            $deduct  = (float) ($deductMap[$row->farmer_id] ?? 0);
            $net     = round($gross - $deduct, 2);

            $result[] = [
                'farmer_no'   => $row->farmer_no,
                'member_no'   => $row->member_no,
                'full_name'   => $row->full_name,
                'id_no'       => $row->id_no,
                'phone'       => $row->phone,
                'bank_name'   => $row->bank_name ?? 'No Bank',
                'account_no'  => $row->account_no,
                'route_name'  => $row->route_name,
                'total_qty'   => (float) $row->total_qty,
                'gross'       => $gross,
                'deductions'  => $deduct,
                'net_pay'     => $net,
            ];

            $totGross  += $gross;
            $totDeduct += $deduct;
            $totNet    += $net;
            $totQty    += (float) $row->total_qty;
        }

        return ApiResponse::success([
            'rows'   => $result,
            'totals' => [
                'farmers'         => count($result),
                'total_qty'       => $totQty,
                'total_gross'     => $totGross,
                'total_deductions'=> $totDeduct,
                'total_net'       => $totNet,
            ],
            'month' => $month,
            'year'  => $year,
        ], 'OK');
    }

    private function zeroTotals(): array
    {
        return ['farmers' => 0, 'total_qty' => 0, 'total_gross' => 0, 'total_deductions' => 0, 'total_net' => 0];
    }
}
