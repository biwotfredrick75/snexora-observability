<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\FarmerCheckoffEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FarmerPaymentController extends Controller
{
    // ── Form dropdowns ─────────────────────────────────────────────────────────
    public function formData(): JsonResponse
    {
        $services = DB::table('checkoff_services')
            ->where('active', true)
            ->orderBy('service_name')
            ->get(['id', 'service_name', 'service_type', 'gl_account']);

        $farmers = DB::table('farmers')
            ->where('status', 'active')
            ->orderBy('full_name')
            ->get(['id', 'farmer_no', 'member_no', 'full_name']);

        $paymentTerms = DB::table('farmer_payment_terms')
            ->where('inactive', false)
            ->orderBy('description')
            ->get(['id', 'description', 'type']);

        $routes = DB::table('milk_routes')
            ->orderBy('route_name')
            ->get(['id', 'route_name']);

        return ApiResponse::success(
            compact('services', 'farmers', 'paymentTerms', 'routes'),
            'Form data'
        );
    }

    // ── Load payment preview data ──────────────────────────────────────────────
    //  POST body: { month, year, member_no?, farmer_id?, route_id?, payment_term_id? }
    public function loadPaymentData(Request $request): JsonResponse
    {
        ini_set('memory_limit', '512M');
        set_time_limit(120);

        $request->validate([
            'month' => 'required|integer|between:1,12',
            'year'  => 'required|integer|min:2000|max:2100',
        ]);

        $month      = (int) $request->month;
        $year       = (int) $request->year;
        $membNo     = $request->member_no;
        $farmerId   = $request->farmer_id;
        $routeId    = $request->route_id;
        $termId     = $request->payment_term_id;

        // Date range (index-friendly — avoids MONTH()/YEAR() full scan)
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate   = date('Y-m-t', strtotime($startDate)); // last day of month

        // ── Step 1: aggregate items directly on the purchase_items table (fast) ─
        // Subquery gets purchase IDs in the date window first, then aggregates.
        $aggSub = DB::table('milk_purchase_items as mpi')
            ->join('milk_purchases as mp', 'mp.id', '=', 'mpi.purchase_id')
            ->whereBetween('mp.invoice_date', [$startDate, $endDate])
            ->where('mp.status', '!=', 'cancelled');

        if ($farmerId) $aggSub->where('mpi.farmer_id', $farmerId);

        $aggSub = $aggSub
            ->selectRaw('
                mpi.farmer_id,
                MAX(mp.due_date)              AS date_due,
                COUNT(DISTINCT mp.id)         AS delivery_count,
                SUM(mpi.quantity)             AS total_qty,
                SUM(mpi.total_price)          AS gross_amount,
                AVG(mpi.unit_price)           AS avg_price
            ')
            ->groupBy('mpi.farmer_id');

        // ── Step 2: join farmer details onto the aggregated results ──────────
        $milkQuery = DB::table(DB::raw('(' . $aggSub->toSql() . ') as agg'))
            ->mergeBindings($aggSub)
            ->join('farmers as f',               'f.id',  '=', 'agg.farmer_id')
            ->leftJoin('milk_routes as mr',       'mr.id', '=', 'f.route_id')
            ->leftJoin('farmer_banks as fb',      'fb.id', '=', 'f.bank_id')
            ->leftJoin('farmer_payment_terms as fpt', 'fpt.id', '=', 'f.payment_terms_id');

        if ($membNo)  $milkQuery->where('f.member_no',        $membNo);
        if ($routeId) $milkQuery->where('f.route_id',         $routeId);
        if ($termId)  $milkQuery->where('f.payment_terms_id', $termId);

        $milkRows = $milkQuery
            ->selectRaw('
                f.id            AS farmer_id,
                f.farmer_no,
                f.member_no,
                f.full_name     AS supplier_name,
                f.account_no,
                f.bank_id,
                fb.name         AS bank_name,
                mr.route_name   AS route,
                fpt.description AS payment_term,
                fpt.type        AS payment_term_type,
                agg.date_due,
                agg.delivery_count,
                agg.total_qty,
                agg.gross_amount,
                agg.avg_price
            ')
            ->orderBy('f.full_name')
            ->get();

        if ($milkRows->isEmpty()) {
            return ApiResponse::success([
                'farmers'  => [],
                'services' => [],
                'totals'   => $this->emptyTotals(),
                'month'    => $month,
                'year'     => $year,
            ], 'No milk collection data found for the selected period');
        }

        $farmerIds = $milkRows->pluck('farmer_id')->all();

        // ── Advances paid to farmers this period ──────────────────────────────
        $advancesMap = DB::table('farmer_payments')
            ->whereIn('farmer_id', $farmerIds)
            ->where('type', 'advance')
            ->whereBetween('date_paid', [$startDate, $endDate])
            ->selectRaw('farmer_id, SUM(amount_payment) AS advance_total')
            ->groupBy('farmer_id')
            ->pluck('advance_total', 'farmer_id');

        // ── Direct invoices (goods sold to farmers) placed this period ────────
        $invoicesMap = DB::table('farmer_direct_invoices')
            ->whereIn('farmer_id', $farmerIds)
            ->where('status', 'placed')
            ->whereBetween('invoice_date', [$startDate, $endDate])
            ->selectRaw('farmer_id, SUM(amount_total) AS invoice_total')
            ->groupBy('farmer_id')
            ->pluck('invoice_total', 'farmer_id');

        // ── External agrovet/service-provider (ESP) sales not yet deducted ────
        // Not scoped to this period — any unsettled sale is owed regardless of
        // when it was made, matching ProcessFarmerPaymentsBatch's actual logic.
        $espMap = DB::table('esp_farmer_sales')
            ->where('party_type', 'farmer')
            ->whereIn('party_id', $farmerIds)
            ->where('party_deducted', false)
            ->where('status', '!=', 'void')
            ->selectRaw('party_id AS farmer_id, SUM(total_amount) AS esp_total')
            ->groupBy('party_id')
            ->pluck('esp_total', 'farmer_id');

        // ── Carry-forward debt from previous period ───────────────────────────
        $carryForwardMap = DB::table('farmer_carry_forwards')
            ->whereIn('farmer_id', $farmerIds)
            ->where('to_month', $month)
            ->where('to_year', $year)
            ->pluck('amount', 'farmer_id');

        // ── Checkoff entries for these farmers for the period ─────────────────
        $checkoffRows = DB::table('farmer_checkoff_entries as fce')
            ->leftJoin('checkoff_services as cs', 'cs.id', '=', 'fce.service_id')
            ->whereIn('fce.farmer_id', $farmerIds)
            ->where('fce.month', $month)
            ->where('fce.year',  $year)
            ->selectRaw('
                fce.farmer_id,
                COALESCE(cs.service_name, fce.service_name) AS service_name,
                fce.service_id,
                cs.service_type,
                fce.amount
            ')
            ->get();

        // ── Active checkoff services (column headers) ─────────────────────────
        $services = DB::table('checkoff_services')
            ->where('active', true)
            ->orderBy('service_name')
            ->get(['id', 'service_name', 'service_type']);

        // ── Build per-farmer deduction map ────────────────────────────────────
        $deductionMap = [];
        foreach ($checkoffRows as $row) {
            $deductionMap[$row->farmer_id][$row->service_id ?? 'other'][] = [
                'service_name' => $row->service_name,
                'service_type' => $row->service_type,
                'amount'       => (float) $row->amount,
            ];
        }

        // ── Combine milk + advances + deductions + invoices + ESP + carry-forward ─
        $farmers = $milkRows->map(function ($row) use (
            $services, $deductionMap, $advancesMap, $invoicesMap, $espMap, $carryForwardMap
        ) {
            $fid           = $row->farmer_id;
            $gross         = (float) $row->gross_amount;
            $advanceAmount = (float) ($advancesMap[$fid] ?? 0);
            $invoiceAmount = (float) ($invoicesMap[$fid] ?? 0);
            $espAmount     = (float) ($espMap[$fid] ?? 0);
            $carryForward  = (float) ($carryForwardMap[$fid] ?? 0);
            $totalDeduct   = 0.0;
            $deductions    = [];

            foreach ($services as $svc) {
                $key    = $svc->id;
                $amount = 0.0;
                if (isset($deductionMap[$fid][$key])) {
                    $amount = array_sum(array_column($deductionMap[$fid][$key], 'amount'));
                }
                $deductions[$key] = $amount;
                $totalDeduct += $amount;
            }

            $net     = $gross - $advanceAmount - $totalDeduct - $invoiceAmount - $espAmount - $carryForward;
            $deficit = $net < 0 ? round(abs($net), 4) : 0;
            $net     = max(0, $net);

            return [
                'farmer_id'            => $fid,
                'farmer_no'            => $row->farmer_no,
                'member_no'            => $row->member_no,
                'supplier_name'        => $row->supplier_name,
                'account_no'           => $row->account_no,
                'bank_id'              => $row->bank_id,
                'bank_name'            => $row->bank_name ?? 'No Bank',
                'route'                => $row->route,
                'payment_term'         => $row->payment_term,
                'payment_term_type'    => $row->payment_term_type,
                'date_due'             => $row->date_due,
                'delivery_count'       => (int)   $row->delivery_count,
                'total_qty'            => (float)  $row->total_qty,
                'gross_amount'         => $gross,
                'avg_price'            => round((float) $row->avg_price, 4),
                'avg_qty_per_delivery' => $row->delivery_count > 0
                    ? round((float) $row->total_qty / (int) $row->delivery_count, 2)
                    : 0,
                'advance_amount'       => round($advanceAmount, 2),
                'invoice_amount'       => round($invoiceAmount, 2),
                'esp_amount'           => round($espAmount, 2),
                'carry_forward'        => round($carryForward, 2),
                'deductions'           => $deductions,
                'total_deductions'     => round($totalDeduct, 4),
                'net_payment'          => round($net, 4),
                'deficit'              => $deficit, // will carry to next month if > 0
            ];
        });

        // ── Grand totals ──────────────────────────────────────────────────────
        $totals = [
            'farmer_count'      => $farmers->count(),
            'total_qty'         => round($farmers->sum('total_qty'), 3),
            'gross_amount'      => round($farmers->sum('gross_amount'), 4),
            'total_advances'    => round($farmers->sum('advance_amount'), 2),
            'total_invoices'    => round($farmers->sum('invoice_amount'), 2),
            'total_esp'         => round($farmers->sum('esp_amount'), 2),
            'total_carry_fwd'   => round($farmers->sum('carry_forward'), 2),
            'total_deductions'  => round($farmers->sum('total_deductions'), 4),
            'net_payment'       => round($farmers->sum('net_payment'), 4),
            'total_deficit'     => round($farmers->sum('deficit'), 4),
        ];

        foreach ($services as $svc) {
            $totals['deductions'][$svc->id] = round(
                $farmers->sum(fn ($f) => $f['deductions'][$svc->id] ?? 0),
                4
            );
        }

        return ApiResponse::success([
            'farmers'  => $farmers,
            'services' => $services,
            'totals'   => $totals,
            'month'    => $month,
            'year'     => $year,
        ], 'Payment data loaded');
    }

    // ── Save / upsert checkoff entries for a batch of farmers ─────────────────
    //  POST body: { month, year, entries: [{ farmer_id, service_id, amount, note? }] }
    public function saveDeductions(Request $request): JsonResponse
    {
        $request->validate([
            'month'              => 'required|integer|between:1,12',
            'year'               => 'required|integer|min:2000|max:2100',
            'entries'            => 'required|array',
            'entries.*.farmer_id' => 'required|integer|exists:farmers,id',
            'entries.*.service_id' => 'required|integer|exists:checkoff_services,id',
            'entries.*.amount'    => 'required|numeric|min:0',
        ]);

        $month   = (int) $request->month;
        $year    = (int) $request->year;
        $now     = now();
        $upserts = [];

        foreach ($request->entries as $e) {
            $upserts[] = [
                'farmer_id'   => $e['farmer_id'],
                'month'       => $month,
                'year'        => $year,
                'service_id'  => $e['service_id'],
                'service_name' => null,
                'amount'      => $e['amount'],
                'note'        => $e['note'] ?? null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
        }

        // Single bulk upsert instead of N individual queries
        DB::table('farmer_checkoff_entries')->upsert(
            $upserts,
            ['farmer_id', 'month', 'year', 'service_id'],
            ['amount', 'note', 'updated_at']
        );

        return ApiResponse::success(null, count($upserts) . ' deduction entries saved');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private function emptyTotals(): array
    {
        return [
            'farmer_count'     => 0,
            'total_qty'        => 0,
            'gross_amount'     => 0,
            'total_advances'   => 0,
            'total_invoices'   => 0,
            'total_carry_fwd'  => 0,
            'total_deductions' => 0,
            'net_payment'      => 0,
            'total_deficit'    => 0,
        ];
    }
}
