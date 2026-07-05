<?php

namespace App\Http\Controllers\CasualWorkers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CasualEarningType;
use App\Models\CasualPayPosting;
use App\Models\CasualWorkerPayRun;
use App\Services\CasualWorkers\CasualPayRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CasualWorkerPayRunController extends Controller
{
    public function __construct(protected CasualPayRunService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = CasualWorkerPayRun::withCount('items')->orderByDesc('period_start');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return ApiResponse::success($query->get(), 'Pay runs retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after_or_equal:period_start',
            'notes'        => 'nullable|string',
        ]);

        // Block any period that overlaps with any existing run regardless of status
        $overlap = CasualWorkerPayRun::where(function ($q) use ($data) {
            $q->where('period_start', '<=', $data['period_end'])
              ->where('period_end',   '>=', $data['period_start']);
        })->exists();

        if ($overlap) {
            return ApiResponse::error('A pay run already exists that covers part of this period. Close or delete it first.', 409);
        }

        $data['ref_no']     = $this->generateRef();
        $data['status']     = 'draft';
        $data['created_by'] = auth()->id();

        return ApiResponse::created(CasualWorkerPayRun::create($data), 'Pay run created');
    }

    public function show(int $id): JsonResponse
    {
        $run = CasualWorkerPayRun::with([
            'items.worker.trade',
            'postings.earningType.glAccount',
        ])->findOrFail($id);

        return ApiResponse::success($run, 'Pay run retrieved');
    }

    public function generate(int $id): JsonResponse
    {
        $run = CasualWorkerPayRun::findOrFail($id);

        if ($run->status === 'approved' || $run->status === 'paid') {
            return ApiResponse::error('Cannot regenerate an approved or paid run.', 409);
        }

        $run = $this->service->generate($run);
        return ApiResponse::success($run, 'Pay run generated from attendance');
    }

    public function generateChunked(Request $request, int $id): JsonResponse
    {
        $run = CasualWorkerPayRun::findOrFail($id);

        if (in_array($run->status, ['approved', 'paid'])) {
            return ApiResponse::error('Cannot regenerate an approved or paid run.', 409);
        }

        $result = $this->service->generateChunk($run, $request->integer('offset', 0));

        return ApiResponse::success($result, $result['done'] ? 'Pay run generated' : 'Processing');
    }

    public function approve(int $id): JsonResponse
    {
        $run = CasualWorkerPayRun::findOrFail($id);

        if ($run->status === 'draft') {
            return ApiResponse::error('Generate the pay run before approving.', 422);
        }

        $run = $this->service->approve($run, auth()->id());
        return ApiResponse::success($run, 'Pay run approved');
    }

    /**
     * Update untyped manual adjustments for one pay item.
     * Recalculates totals from postings + the new manual amounts.
     */
    public function updateItem(Request $request, int $runId, int $itemId): JsonResponse
    {
        $run = CasualWorkerPayRun::findOrFail($runId);
        if (in_array($run->status, ['approved', 'paid'])) {
            return ApiResponse::error('Cannot edit an approved or paid run.', 409);
        }

        $item = $run->items()->findOrFail($itemId);
        $data = $request->validate([
            'other_allowances' => 'nullable|numeric|min:0',
            'deductions'       => 'nullable|numeric|min:0',
        ]);

        // Compute the auto posting sums for this item
        $autoAllowances = $item->other_allowances - $item->manual_allowances;
        $autoDeductions = $item->deductions       - $item->manual_deductions;

        if (isset($data['other_allowances'])) {
            $item->manual_allowances = max(0, round($data['other_allowances'] - $autoAllowances, 2));
            $item->other_allowances  = round($autoAllowances + $item->manual_allowances, 2);
        }
        if (isset($data['deductions'])) {
            $item->manual_deductions = max(0, round($data['deductions'] - $autoDeductions, 2));
            $item->deductions        = round($autoDeductions + $item->manual_deductions, 2);
        }

        $item->net_pay = round(
            $item->base_pay + $item->transport_allowance + $item->meal_allowance
            + $item->other_allowances - $item->deductions,
            2
        );
        $item->save();

        $run->update(['total_amount' => $run->items()->sum('net_pay')]);

        return ApiResponse::updated($item->load('worker'), 'Item updated');
    }

    /** Post a type to ALL workers in the run. */
    public function postEarnings(Request $request, int $id): JsonResponse
    {
        $run = CasualWorkerPayRun::with(['items.worker.trade'])->findOrFail($id);

        if (in_array($run->status, ['approved', 'paid'])) {
            return ApiResponse::error('Cannot post to an approved or paid run.', 409);
        }

        $data = $request->validate([
            'earning_type_id' => 'required|integer|exists:casual_earning_types,id',
            'amount'          => 'nullable|numeric|min:0',
            'note'            => 'nullable|string|max:255',
        ]);

        $type = CasualEarningType::findOrFail($data['earning_type_id']);

        DB::transaction(function () use ($run, $type, $data) {
            foreach ($run->items as $item) {
                $this->applyManualPosting($run, $item, $type, $data);
            }
            $run->update(['total_amount' => $run->items()->sum('net_pay')]);
        });

        $run->refresh()->load(['items.worker.trade', 'postings.earningType.glAccount']);
        return ApiResponse::success($run, 'Earnings posted to all workers');
    }

    /** Post a type to a SPECIFIC worker in the run. */
    public function postWorkerEarning(Request $request, int $id): JsonResponse
    {
        $run = CasualWorkerPayRun::with(['items.worker.trade'])->findOrFail($id);

        if (in_array($run->status, ['approved', 'paid'])) {
            return ApiResponse::error('Cannot post to an approved or paid run.', 409);
        }

        $data = $request->validate([
            'worker_id'       => 'required|integer',
            'earning_type_id' => 'required|integer|exists:casual_earning_types,id',
            'amount'          => 'nullable|numeric|min:0',
            'note'            => 'nullable|string|max:255',
        ]);

        $type = CasualEarningType::findOrFail($data['earning_type_id']);
        $item = $run->items()->where('worker_id', $data['worker_id'])->firstOrFail();

        DB::transaction(function () use ($run, $item, $type, $data) {
            $this->applyManualPosting($run, $item, $type, $data);
            $run->update(['total_amount' => $run->items()->sum('net_pay')]);
        });

        $run->refresh()->load(['items.worker.trade', 'postings.earningType.glAccount']);
        return ApiResponse::success($run, 'Earning/deduction posted to worker');
    }

    /**
     * Apply a manual (is_auto=false) posting to one item.
     * Upserts the posting and recalculates the item's running totals.
     */
    private function applyManualPosting(CasualWorkerPayRun $run, $item, CasualEarningType $type, array $data): void
    {
        $worker     = $item->worker;
        $payWelfare = $worker->trade?->pay_welfare ?? true;
        if ($type->welfare_only && !$payWelfare) return;

        $amount = isset($data['amount']) && $data['amount'] !== null
            ? (float)$data['amount']
            : ($type->frequency === 'daily'
                ? round($type->default_amount * $item->days_worked, 2)
                : (float)$type->default_amount);

        // Upsert on the unique key (pay_run_id, worker_id, earning_type_id)
        CasualPayPosting::updateOrCreate(
            ['pay_run_id' => $run->id, 'worker_id' => $item->worker_id, 'earning_type_id' => $type->id],
            ['amount' => $amount, 'note' => $data['note'] ?? null, 'is_auto' => false]
        );

        // Recalculate item totals from all postings + untyped manual adjustments
        $this->recalcItemFromPostings($run->id, $item);
        $item->save();
    }

    /** Recompute other_allowances / deductions / net_pay from the current posting rows. */
    private function recalcItemFromPostings(int $runId, $item): void
    {
        $sums = CasualPayPosting::where('pay_run_id', $runId)
            ->where('worker_id', $item->worker_id)
            ->join('casual_earning_types', 'casual_pay_postings.earning_type_id', '=', 'casual_earning_types.id')
            ->selectRaw('casual_earning_types.category, SUM(casual_pay_postings.amount) as total')
            ->groupBy('casual_earning_types.category')
            ->pluck('total', 'category');

        $item->other_allowances = round((float)($sums['earning']   ?? 0) + $item->manual_allowances, 2);
        $item->deductions       = round((float)($sums['deduction'] ?? 0) + $item->manual_deductions, 2);
        $item->net_pay          = round(
            $item->base_pay + $item->transport_allowance + $item->meal_allowance
            + $item->other_allowances - $item->deductions,
            2
        );
    }

    public function mpesaExport(int $id): Response
    {
        $run  = CasualWorkerPayRun::with('items.worker')->findOrFail($id);
        $rows = $this->service->mpesaExportRows($run);

        $csv = "PhoneNumber,Name,Amount,Remarks\n";
        foreach ($rows as $r) {
            $csv .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', $v) . '"', $r)) . "\n";
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"mpesa_{$run->ref_no}.csv\"",
        ]);
    }

    public function payRunReport(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date',
        ]);

        $query = CasualWorkerPayRun::with('items.worker.trade')
            ->whereIn('status', ['approved', 'paid'])
            ->orderByDesc('period_start');

        if ($request->filled('date_from')) $query->where('period_start', '>=', $request->date_from);
        if ($request->filled('date_to'))   $query->where('period_end',   '<=', $request->date_to);

        return ApiResponse::success($query->get(), 'Pay run report retrieved');
    }

    private function generateRef(): string
    {
        $last = CasualWorkerPayRun::orderByDesc('id')->value('ref_no');
        if (!$last) return 'CPR-001/' . now()->year;

        preg_match('/CPR-(\d+)/', $last, $m);
        $next = isset($m[1]) ? (int)$m[1] + 1 : 1;
        return 'CPR-' . str_pad($next, 3, '0', STR_PAD_LEFT) . '/' . now()->year;
    }
}
