<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServicePostingController extends Controller
{
    // ── Form dropdowns ────────────────────────────────────────────────────────
    public function formData(): JsonResponse
    {
        return ApiResponse::success([
            'services' => DB::table('checkoff_services')
                ->where('active', true)
                ->orderBy('service_name')
                ->get(['id', 'service_name', 'service_type', 'gl_account']),
            'farmers' => DB::table('farmers')
                ->where('status', 'active')
                ->orderBy('full_name')
                ->get(['id', 'farmer_no', 'member_no', 'full_name']),
        ], 'Form data retrieved');
    }

    // ── List posted service entries ───────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('farmer_checkoff_entries as fce')
            ->join('farmers as f',          'f.id',  '=', 'fce.farmer_id')
            ->leftJoin('checkoff_services as cs', 'cs.id', '=', 'fce.service_id')
            ->select([
                'fce.id',
                'f.farmer_no    as member_no',
                'f.full_name    as name',
                'fce.month',
                'fce.year',
                DB::raw("COALESCE(cs.service_name, fce.service_name, '—') as service_name"),
                'cs.service_type',
                'fce.amount',
                'fce.note',
                'fce.created_at',
            ])
            ->orderByDesc('fce.year')
            ->orderByDesc('fce.month')
            ->orderBy('f.full_name');

        if ($request->filled('farmer_id')) {
            $query->where('fce.farmer_id', $request->integer('farmer_id'));
        }
        if ($request->filled('month')) {
            $query->where('fce.month', $request->integer('month'));
        }
        if ($request->filled('year')) {
            $query->where('fce.year', $request->integer('year'));
        }

        return ApiResponse::success($query->limit(500)->get(), 'Service postings retrieved');
    }

    // ── Post a new service entry ───────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'farmer_id'  => 'required|integer|exists:farmers,id',
            'service_id' => 'required|integer|exists:checkoff_services,id',
            'date'       => 'required|date',
            'amount'     => 'required|numeric|min:0.01',
            'note'       => 'nullable|string|max:255',
        ]);

        $date  = \Carbon\Carbon::parse($data['date']);
        $month = (int) $date->format('n');
        $year  = (int) $date->format('Y');

        $id = DB::table('farmer_checkoff_entries')->insertGetId([
            'farmer_id'    => $data['farmer_id'],
            'service_id'   => $data['service_id'],
            'month'        => $month,
            'year'         => $year,
            'amount'       => $data['amount'],
            'note'         => $data['note'] ?? null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $entry = DB::table('farmer_checkoff_entries as fce')
            ->join('farmers as f', 'f.id', '=', 'fce.farmer_id')
            ->leftJoin('checkoff_services as cs', 'cs.id', '=', 'fce.service_id')
            ->where('fce.id', $id)
            ->select([
                'fce.id',
                'f.farmer_no  as member_no',
                'f.full_name  as name',
                'fce.month', 'fce.year',
                DB::raw("COALESCE(cs.service_name, fce.service_name, '—') as service_name"),
                'cs.service_type',
                'fce.amount', 'fce.note',
            ])
            ->first();

        return ApiResponse::created($entry, 'Service posting saved');
    }

    // ── Delete a single entry ─────────────────────────────────────────────────
    public function destroy(int $id): JsonResponse
    {
        $deleted = DB::table('farmer_checkoff_entries')->where('id', $id)->delete();
        if (! $deleted) {
            return ApiResponse::notFound('Entry not found');
        }
        return ApiResponse::deleted('Service posting deleted');
    }

    // ── Report: entries within a date range ───────────────────────────────────
    public function report(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date',
            'service_id' => 'nullable|integer|exists:checkoff_services,id',
        ]);

        $from = $request->filled('start_date') ? \Carbon\Carbon::parse($request->start_date) : now()->startOfMonth();
        $to   = $request->filled('end_date')   ? \Carbon\Carbon::parse($request->end_date)   : now()->endOfMonth();

        $rows = DB::table('farmer_checkoff_entries as fce')
            ->join('farmers as f', 'f.id', '=', 'fce.farmer_id')
            ->leftJoin('checkoff_services as cs', 'cs.id', '=', 'fce.service_id')
            ->where(function ($q) use ($from, $to) {
                $q->whereRaw('MAKEDATE(fce.year, 1) + INTERVAL (fce.month - 1) MONTH >= ?', [$from->format('Y-m-01')])
                  ->whereRaw('MAKEDATE(fce.year, 1) + INTERVAL (fce.month - 1) MONTH <= ?', [$to->format('Y-m-01')]);
            })
            ->when($request->filled('service_id'), fn ($q) => $q->where('fce.service_id', $request->service_id))
            ->select([
                'f.farmer_no    as member_no',
                'f.full_name    as name',
                'fce.month', 'fce.year',
                DB::raw("COALESCE(cs.service_name, fce.service_name, '—') as service_name"),
                'cs.service_type',
                'fce.amount',
                'fce.note',
            ])
            ->orderBy('fce.year')
            ->orderBy('fce.month')
            ->orderBy('f.full_name')
            ->get();

        return ApiResponse::success([
            'rows'         => $rows,
            'total_amount' => $rows->sum('amount'),
        ], 'Service postings report');
    }
}
