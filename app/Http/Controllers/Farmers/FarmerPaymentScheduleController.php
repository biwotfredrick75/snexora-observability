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

        // ── Every deduction source a real farmer payment run nets against —
        // must mirror ProcessFarmerPaymentsBatch::runBatch() exactly, or this
        // "preview" report understates what the office will actually pay out
        // once they process the batch. This is read-only: unlike the batch
        // job, nothing here is marked as settled/deducted.
        $checkoffMap = DB::table('farmer_checkoff_entries')
            ->whereIn('farmer_id', $farmerIds)
            ->where('month', $month)->where('year', $year)
            ->where('deducted', false)
            ->selectRaw('farmer_id, SUM(amount) AS total')
            ->groupBy('farmer_id')
            ->pluck('total', 'farmer_id');

        $advancesMap = DB::table('farmer_payments')
            ->whereIn('farmer_id', $farmerIds)
            ->where('type', 'advance')
            ->where('recovered', false)
            ->whereBetween('date_paid', [$dateFrom, $dateTo])
            ->selectRaw('farmer_id, SUM(amount_payment) AS total')
            ->groupBy('farmer_id')
            ->pluck('total', 'farmer_id');

        $invoicesMap = DB::table('farmer_direct_invoices')
            ->whereIn('farmer_id', $farmerIds)
            ->where('status', 'placed')
            ->where('settled', false)
            ->whereBetween('invoice_date', [$dateFrom, $dateTo])
            ->selectRaw('farmer_id, SUM(amount_total) AS total')
            ->groupBy('farmer_id')
            ->pluck('total', 'farmer_id');

        $espMap = DB::table('esp_farmer_sales')
            ->where('party_type', 'farmer')
            ->whereIn('party_id', $farmerIds)
            ->where('party_deducted', false)
            ->where('status', '!=', 'void')
            ->selectRaw('party_id AS farmer_id, SUM(total_amount) AS total')
            ->groupBy('party_id')
            ->pluck('total', 'farmer_id');

        $carryForwardMap = DB::table('farmer_carry_forwards')
            ->whereIn('farmer_id', $farmerIds)
            ->where('to_month', $month)
            ->where('to_year', $year)
            ->pluck('amount', 'farmer_id');

        // Regular Sales module invoices against a customer linked to this
        // farmer (customers.farmer_no) — a second, separate "sales to
        // farmers" channel from farmer_direct_invoices above (e.g. a farmer
        // buying at a company shop/POS as a normal customer). Joined against
        // farmers.farmer_no rather than just checking customers.farmer_no is
        // non-null, since a handful of customer records carry a farmer_no-
        // shaped value that doesn't actually match any real farmer.
        // Outstanding = amount_total minus whatever is already allocated via
        // debtor_allocations — status stays 'placed' even once fully
        // allocated (same as CustomerPaymentController), so without this an
        // already-settled invoice would still show as a pending deduction.
        $salesInvoiceMap = DB::table('sales_invoices as si')
            ->join('customers as c', 'c.debtor_no', '=', 'si.debtor_no')
            ->join('farmers as f2', 'f2.farmer_no', '=', 'c.farmer_no')
            ->leftJoin('debtor_allocations as da', 'da.inv_id', '=', 'si.id')
            ->whereIn('f2.id', $farmerIds)
            ->where('si.status', 'placed')
            ->whereBetween('si.invoice_date', [$dateFrom, $dateTo])
            ->groupBy('f2.id', 'si.id', 'si.amount_total')
            ->selectRaw('f2.id AS farmer_id, si.amount_total - COALESCE(SUM(da.amount), 0) AS outstanding')
            ->get()
            ->groupBy('farmer_id')
            ->map(fn ($rows) => $rows->sum(fn ($r) => max(0, (float) $r->outstanding)));

        $result = [];
        $totGross = $totDeduct = $totNet = $totQty = 0;

        foreach ($rows as $row) {
            $gross      = (float) $row->gross_amount;
            $checkoff   = (float) ($checkoffMap[$row->farmer_id] ?? 0);
            $advance    = (float) ($advancesMap[$row->farmer_id] ?? 0);
            $invoice    = (float) ($invoicesMap[$row->farmer_id] ?? 0);
            $esp        = (float) ($espMap[$row->farmer_id] ?? 0);
            $carryFwd   = (float) ($carryForwardMap[$row->farmer_id] ?? 0);
            $salesInv   = (float) ($salesInvoiceMap[$row->farmer_id] ?? 0);
            $deduct     = round($checkoff + $advance + $invoice + $esp + $carryFwd + $salesInv, 2);
            $net        = round(max(0, $gross - $deduct), 2);

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
                'deduction_breakdown' => [
                    'checkoffs'      => round($checkoff, 2),
                    'advances'       => round($advance, 2),
                    'direct_invoices'=> round($invoice, 2),
                    'sales_invoices' => round($salesInv, 2),
                    'esp_purchases'  => round($esp, 2),
                    'carry_forward'  => round($carryFwd, 2),
                ],
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
