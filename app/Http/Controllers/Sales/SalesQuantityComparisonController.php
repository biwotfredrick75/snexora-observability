<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesQuantityComparisonController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'stock_id'  => 'required|string|max:20',
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
        ]);

        $stockId   = $request->get('stock_id');
        $dateFrom  = $request->get('date_from');
        $dateTo    = $request->get('date_to');

        // ── Raw rows: one row per (customer, date) ─────────────────────────
        $rows = DB::table('sales_invoices as i')
            ->join('sales_invoice_items as ii', 'ii.inv_id', '=', 'i.id')
            ->join('customers as c', 'c.debtor_no', '=', 'i.debtor_no')
            ->leftJoin('customer_branches as cb', 'cb.id', '=', 'i.branch_id')
            ->leftJoin('sales_groups as sg', 'sg.id', '=', 'cb.sales_group_id')
            ->where('ii.stock_id', $stockId)
            ->whereBetween('i.invoice_date', [$dateFrom, $dateTo])
            ->where('i.status', 'placed')
            ->select([
                'i.debtor_no',
                'c.name',
                DB::raw("COALESCE(sg.group_name, '—') as category"),
                DB::raw("COALESCE(c.phone, '') as phone"),
                'i.invoice_date',
                DB::raw('SUM(ii.qty) as qty'),
            ])
            ->groupBy('i.debtor_no', 'c.name', 'cb.sales_group_id', 'sg.group_name', 'c.phone', 'i.invoice_date')
            ->orderBy('c.name')
            ->orderBy('i.invoice_date')
            ->get();

        // ── Pivot: collect unique dates ────────────────────────────────────
        $dates = $rows->pluck('invoice_date')->unique()->sort()->values()->all();

        // ── Group by customer ──────────────────────────────────────────────
        $grouped = [];
        foreach ($rows as $row) {
            $key = $row->debtor_no;
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'debtor_no' => $row->debtor_no,
                    'name'      => $row->name,
                    'category'  => $row->category,
                    'phone'     => $row->phone,
                    'quantities'=> [],
                    'total'     => 0,
                ];
            }
            $qty = round((float) $row->qty, 4);
            $grouped[$key]['quantities'][$row->invoice_date] = $qty;
            $grouped[$key]['total'] += $qty;
        }

        return ApiResponse::success([
            'dates' => $dates,
            'rows'  => array_values($grouped),
        ], 'Sales quantity comparison retrieved');
    }
}
