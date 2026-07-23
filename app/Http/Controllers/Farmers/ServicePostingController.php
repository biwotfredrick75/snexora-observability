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
    //  farmers is search-driven (there are 16,000+ active farmers — loading
    //  them all unpaginated stalls the dropdown) — same pattern as
    //  FarmerSupplierPaymentController::formData().
    public function formData(Request $request): JsonResponse
    {
        $search = $request->input('search', '');
        $farmersQuery = DB::table('farmers')
            ->where('status', 'active')
            ->orderBy('full_name');

        if ($search) {
            $farmersQuery->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('farmer_no', 'like', "%{$search}%")
                  ->orWhere('member_no', 'like', "%{$search}%");
            });
        }

        return ApiResponse::success([
            'services' => DB::table('checkoff_services')
                ->where('active', true)
                ->orderBy('service_name')
                ->get(['id', 'service_name', 'service_type', 'gl_account']),
            'farmers' => $farmersQuery->limit(100)->get(['id', 'farmer_no', 'member_no', 'full_name']),
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
                'fce.deducted',
                'fce.deducted_at',
                'fce.deducted_ref',
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

        // A Deduction posting has to land on a GL account when the payment
        // batch processes it (see ProcessFarmerPaymentsBatch) — block the
        // posting at the source rather than let it silently fall through to
        // a generic bank/misc deduction line.
        $service = DB::table('checkoff_services')->where('id', $data['service_id'])->first();
        if ($service && $service->service_type === 'Deduction' && empty($service->gl_account)) {
            return ApiResponse::validationError([
                'service_id' => ["\"{$service->service_name}\" has no GL account configured — set one in Farmer Check-off Services before posting deductions against it."],
            ]);
        }

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
                'fce.deducted', 'fce.deducted_at', 'fce.deducted_ref',
            ])
            ->first();

        return ApiResponse::created($entry, 'Service posting saved');
    }

    // ── Delete a single entry ─────────────────────────────────────────────────
    //  Refuses to delete an entry a farmer payment batch has already
    //  processed (deducted=true) — it's been netted off gross pay and posted
    //  to GL by then (ProcessFarmerPaymentsBatch), so deleting it here would
    //  silently desync the ledger from what was actually paid.
    public function destroy(int $id): JsonResponse
    {
        $entry = DB::table('farmer_checkoff_entries')->where('id', $id)->first();
        if (! $entry) {
            return ApiResponse::notFound('Entry not found');
        }
        if ($entry->deducted) {
            $ref = $entry->deducted_ref ? " (ref {$entry->deducted_ref})" : '';
            return ApiResponse::validationError([
                'id' => ["This posting has already been processed{$ref} and cannot be deleted."],
            ]);
        }

        DB::table('farmer_checkoff_entries')->where('id', $id)->delete();
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
