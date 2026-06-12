<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MilkPayslipController extends Controller
{
    public function formData(): JsonResponse
    {
        $routes  = DB::table('milk_routes')->orderBy('route_name')->get(['id', 'route_code', 'route_name']);
        $company = DB::table('company_preferences')->first(['name', 'phone', 'email', 'logo_filename']);

        $years = [];
        $currentYear = (int) date('Y');
        for ($y = $currentYear; $y >= $currentYear - 5; $y--) $years[] = $y;

        return ApiResponse::success([
            'routes'  => $routes,
            'company' => $company,
            'years'   => $years,
        ], 'OK');
    }

    public function payslips(Request $request): JsonResponse
    {
        $request->validate([
            'filter_type'   => 'required|in:route,individual',
            'month'         => 'required|integer|min:1|max:12',
            'year'          => 'required|integer|min:2000',
            'route_id'      => 'nullable|integer|exists:milk_routes,id',
            'farmer_id'     => 'nullable|integer|exists:farmers,id',
        ]);

        $month = (int) $request->month;
        $year  = (int) $request->year;

        $dateFrom = Carbon::create($year, $month, 1)->startOfMonth();
        $dateTo   = $dateFrom->copy()->endOfMonth();

        // Determine which farmers to process
        $farmerQuery = DB::table('farmers as f')
            ->leftJoin('milk_routes as r', 'r.id', '=', 'f.route_id')
            ->leftJoin('farmer_banks as b', 'b.id', '=', 'f.bank_id')
            ->select('f.id', 'f.farmer_no', 'f.full_name', 'f.account_no', 'f.phone',
                     DB::raw('b.name as bank_name'), DB::raw('r.route_name'));

        if ($request->filter_type === 'route' && $request->filled('route_id')) {
            $farmerQuery->where('f.route_id', $request->route_id);
        } elseif ($request->filter_type === 'individual' && $request->filled('farmer_id')) {
            $farmerQuery->where('f.id', $request->farmer_id);
        }

        $farmers = $farmerQuery->orderBy('f.farmer_no')->get();

        // Build payslips
        $payslips = [];
        $company  = DB::table('company_preferences')->first(['name', 'phone', 'logo_filename']);

        foreach ($farmers as $farmer) {
            // ── Daily milk data pivoted by morning/evening shift ──────────────
            $purchases = DB::table('milk_purchase_items as mpi')
                ->join('milk_purchases as mp', 'mp.id', '=', 'mpi.purchase_id')
                ->leftJoin('milk_collection_shifts as s', 's.id', '=', 'mp.shift_id')
                ->where('mpi.farmer_id', $farmer->id)
                ->where('mp.status', 'approved')
                ->whereBetween('mp.invoice_date', [$dateFrom->format('Y-m-d'), $dateTo->format('Y-m-d')])
                ->select([
                    'mp.invoice_date as date',
                    DB::raw("CASE WHEN LOWER(s.description) LIKE '%morning%' OR LOWER(s.description) LIKE '%day%' THEN 'morning' ELSE 'evening' END as shift_cat"),
                    DB::raw('SUM(mpi.quantity) as qty'),
                    DB::raw('AVG(mpi.unit_price) as price'),
                    DB::raw('SUM(mpi.total_price) as amount'),
                ])
                ->groupBy('mp.invoice_date', 'shift_cat')
                ->get()
                ->groupBy('date');

            // Build daily rows
            $rows = [];
            $totMorn = $totEven = $totQty = $totAmt = 0;
            $avgPrice = 0;
            $priceCount = 0;

            for ($d = $dateFrom->copy(); $d->lte($dateTo); $d->addDay()) {
                $ds  = $d->format('Y-m-d');
                $day = $purchases->get($ds, collect());

                $morn = $day->firstWhere('shift_cat', 'morning');
                $even = $day->firstWhere('shift_cat', 'evening');

                $mq = (float)($morn->qty   ?? 0);
                $mp = (float)($morn->price ?? 0);
                $eq = (float)($even->qty   ?? 0);
                $ep = (float)($even->price ?? 0);
                $tq = $mq + $eq;
                $ta = (float)($morn->amount ?? 0) + (float)($even->amount ?? 0);

                $rows[] = ['date' => $ds, 'morning' => $mq, 'evening' => $eq, 'total' => $tq, 'amount' => $ta];
                $totMorn += $mq; $totEven += $eq; $totQty += $tq; $totAmt += $ta;
                if ($mp) { $avgPrice += $mp; $priceCount++; }
                if ($ep) { $avgPrice += $ep; $priceCount++; }
            }

            $price = $priceCount ? round($avgPrice / $priceCount, 2) : 0;

            // ── Checkoff deductions for this month/year ───────────────────────
            $checkoff = DB::table('farmer_checkoff_entries')
                ->where('farmer_id', $farmer->id)
                ->where('month', $month)
                ->where('year', $year)
                ->get(['service_name', 'amount']);

            $deductions = [];
            $totalDeductions = 0;
            foreach ($checkoff as $c) {
                $deductions[]    = ['label' => $c->service_name, 'amount' => (float)$c->amount];
                $totalDeductions += (float)$c->amount;
            }

            $netPay = round($totAmt - $totalDeductions, 2);

            $payslips[] = [
                'farmer'       => $farmer,
                'rows'         => $rows,
                'totals'       => [
                    'morning'  => $totMorn,
                    'evening'  => $totEven,
                    'qty'      => $totQty,
                    'amount'   => $totAmt,
                    'price'    => $price,
                ],
                'deductions'   => $deductions,
                'total_deductions' => $totalDeductions,
                'net_pay'      => $netPay,
            ];
        }

        return ApiResponse::success([
            'payslips' => $payslips,
            'company'  => $company,
            'month'    => $month,
            'year'     => $year,
            'month_name' => Carbon::create($year, $month, 1)->format('F'),
        ], 'OK');
    }
}
