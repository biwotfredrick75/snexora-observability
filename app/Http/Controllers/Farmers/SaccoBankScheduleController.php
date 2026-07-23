<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaccoBankScheduleController extends Controller
{
    // ── Form dropdown data ──────────────────────────────────────────────────────
    public function formData(): JsonResponse
    {
        $services = DB::table('checkoff_services')->orderBy('service_name')->get(['id', 'service_name']);
        $company  = DB::table('company_preferences')->first(['name', 'phone', 'email', 'logo_filename']);

        $years = [];
        $currentYear = (int) date('Y');
        for ($y = $currentYear; $y >= $currentYear - 5; $y--) $years[] = $y;

        return ApiResponse::success(compact('services', 'company', 'years'), 'Form data');
    }

    // ── SACCO Bank Schedule ──────────────────────────────────────────────────────
    // Lists loan repayments and other checkoff deductions that have already
    // been recovered from farmers (fce.deducted = true, i.e. actually taken
    // out of a farmer's milk payment) for a period, so finance can remit the
    // total to the SACCO in one bank payment, backed by this itemized detail.
    public function schedule(Request $request): JsonResponse
    {
        $request->validate([
            'month'      => 'required|integer|between:1,12',
            'year'       => 'required|integer|min:2000|max:2100',
            'service_id' => 'nullable|integer|exists:checkoff_services,id',
        ]);

        $month     = (int) $request->month;
        $year      = (int) $request->year;
        $serviceId = $request->service_id;

        $rows = DB::table('farmer_checkoff_entries as fce')
            ->join('farmers as f', 'f.id', '=', 'fce.farmer_id')
            ->leftJoin('checkoff_services as cs', 'cs.id', '=', 'fce.service_id')
            ->where('fce.month', $month)
            ->where('fce.year', $year)
            ->where('fce.deducted', true)
            ->when($serviceId, fn ($q) => $q->where('fce.service_id', $serviceId))
            ->select([
                'fce.id',
                'f.farmer_no as member_no',
                'f.full_name as name',
                DB::raw("COALESCE(cs.service_name, fce.service_name, '—') as service_name"),
                'fce.amount',
                'fce.deducted_at',
                'fce.deducted_ref',
            ])
            ->orderBy('service_name')
            ->orderBy('f.full_name')
            ->get();

        $serviceTotals = $rows->groupBy('service_name')->map(fn ($grp) => [
            'service_name' => $grp->first()->service_name,
            'count'        => $grp->count(),
            'amount'       => round($grp->sum('amount'), 2),
        ])->values();

        return ApiResponse::success([
            'rows'           => $rows,
            'service_totals' => $serviceTotals,
            'total'          => round($rows->sum('amount'), 2),
            'farmer_count'   => $rows->pluck('member_no')->unique()->count(),
            'month'          => $month,
            'year'           => $year,
            'month_name'     => Carbon::create($year, $month, 1)->format('F'),
        ], 'SACCO bank schedule generated');
    }
}
