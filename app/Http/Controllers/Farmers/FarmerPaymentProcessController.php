<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Jobs\ProcessFarmerPaymentsBatch;
use App\Models\FarmerPaymentBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FarmerPaymentProcessController extends Controller
{
    // Same (month, year, term, route) scope a batch runs against — used only
    // for the in-flight (processing/pending) check, so two different scopes
    // (e.g. an advance run vs. the real settlement) don't block each other
    // while both are legitimately mid-run. Closing, below, is different: it
    // applies to the whole month regardless of scope.
    private function scopedBatchQuery(int $month, int $year, ?int $termId, ?int $routeId)
    {
        $query = FarmerPaymentBatch::where('month', $month)->where('year', $year);
        $termId  ? $query->where('payment_term_id', $termId)  : $query->whereNull('payment_term_id');
        $routeId ? $query->where('route_id', $routeId)        : $query->whereNull('route_id');
        return $query;
    }

    // A locked batch (a completed full settlement — advance runs never
    // lock) closes the entire month against ANY further processing, no
    // matter what term/route the new request scopes to.
    private function monthClosedBatch(int $month, int $year): ?FarmerPaymentBatch
    {
        return FarmerPaymentBatch::where('month', $month)
            ->where('year', $year)
            ->where('locked', true)
            ->latest()
            ->first();
    }

    // ── Check if a batch already exists for this period ───────────────────────
    public function checkPeriod(Request $request): JsonResponse
    {
        $request->validate([
            'month' => 'required|integer|between:1,12',
            'year'  => 'required|integer|min:2000|max:2100',
        ]);

        $month = (int) $request->month;
        $year  = (int) $request->year;

        $closed = $this->monthClosedBatch($month, $year);
        if ($closed) {
            return ApiResponse::success([
                'already_processed' => true,
                'locked'            => true,
                'batch'             => $closed,
            ], 'Period check');
        }

        $termId  = $request->payment_term_id ?: null;
        $routeId = $request->route_id ?: null;

        $batch = $this->scopedBatchQuery($month, $year, $termId, $routeId)
            ->whereIn('status', ['processing', 'pending'])
            ->latest()
            ->first();

        return ApiResponse::success([
            'already_processed' => $batch !== null,
            'locked'            => false,
            'batch'             => $batch,
        ], 'Period check');
    }

    // ── Initiate a new payment batch ──────────────────────────────────────────
    public function initiate(Request $request): JsonResponse
    {
        $request->validate([
            'month'     => 'required|integer|between:1,12',
            'year'      => 'required|integer|min:2000|max:2100',
            'date_paid' => 'required|date',
        ]);

        $month = (int) $request->month;
        $year  = (int) $request->year;

        // Closed for the month — refuse outright, regardless of which
        // term/route this request scopes to. Preview screens (payment
        // report/schedule) stay available regardless since they never
        // check batch status.
        $closed = $this->monthClosedBatch($month, $year);
        if ($closed) {
            return ApiResponse::validationError([
                'batch' => [
                    'Payment for ' . date('F', mktime(0, 0, 0, $month, 1)) . ' ' . $year
                    . ' is already closed and posted (batch ' . $closed->reference . '). '
                    . 'No further processing is allowed for this month — '
                    . 'use the Farmer Payment Report to preview instead.',
                ],
            ]);
        }

        $termId  = $request->payment_term_id ?: null;
        $routeId = $request->route_id        ?: null;

        $existing = $this->scopedBatchQuery($month, $year, $termId, $routeId)
            ->whereIn('status', ['processing', 'pending'])
            ->latest()
            ->first();

        if ($existing) {
            return ApiResponse::success(
                ['batch_id' => $existing->id, 'status' => $existing->status],
                'A batch is already processing for this period'
            );
        }

        // Generate reference
        $ref = 'FPY-' . $request->year . sprintf('%02d', $request->month) . '-' . now()->format('His');

        $user = $request->user();
        $batch = FarmerPaymentBatch::create([
            'month'           => $request->month,
            'year'            => $request->year,
            'date_paid'       => $request->date_paid,
            'reference'       => $ref,
            'payment_term_id' => $termId,
            'route_id'        => $routeId,
            'status'          => 'pending',
            'created_by'      => $user?->real_name ?? $user?->user_id ?? 'system',
        ]);

        // Dispatch to queue
        ProcessFarmerPaymentsBatch::dispatch($batch->id)
            ->onQueue('default');

        return ApiResponse::success([
            'batch_id'  => $batch->id,
            'reference' => $ref,
            'status'    => 'pending',
        ], 'Payment batch initiated. Processing started.');
    }

    // ── Poll progress ─────────────────────────────────────────────────────────
    public function status(string $batchId): JsonResponse
    {
        $batch = FarmerPaymentBatch::findOrFail($batchId);

        $pct = $batch->total_farmers > 0
            ? round($batch->processed_count / $batch->total_farmers * 100, 1)
            : 0;

        return ApiResponse::success([
            'id'               => $batch->id,
            'reference'        => $batch->reference,
            'status'           => $batch->status,
            'total_farmers'    => $batch->total_farmers,
            'processed_count'  => $batch->processed_count,
            'failed_count'     => $batch->failed_count,
            'percentage'       => $pct,
            'total_gross'      => (float) $batch->total_gross,
            'total_advances'   => (float) $batch->total_advances,
            'total_deductions' => (float) $batch->total_deductions,
            'total_net'        => (float) $batch->total_net,
            'error_message'    => $batch->error_message,
            'started_at'       => $batch->started_at?->toISOString(),
            'completed_at'     => $batch->completed_at?->toISOString(),
        ], 'Batch status');
    }

    // ── Retry a failed batch ──────────────────────────────────────────────────
    public function retry(string $batchId): JsonResponse
    {
        $batch = FarmerPaymentBatch::findOrFail($batchId);

        if ($batch->status !== 'failed') {
            return ApiResponse::success(
                ['batch_id' => $batch->id, 'status' => $batch->status],
                'Batch is not in a failed state'
            );
        }

        $batch->update([
            'status'          => 'pending',
            'error_message'   => null,
            'processed_count' => 0,
            'failed_count'    => 0,
            'total_gross'     => 0,
            'total_deductions'=> 0,
            'total_net'       => 0,
            'started_at'      => null,
            'completed_at'    => null,
        ]);

        ProcessFarmerPaymentsBatch::dispatch($batch->id)->onQueue('default');

        return ApiResponse::success(
            ['batch_id' => $batch->id, 'status' => 'pending'],
            'Batch queued for retry'
        );
    }

    // ── List recent batches ───────────────────────────────────────────────────
    public function history(Request $request): JsonResponse
    {
        $batches = FarmerPaymentBatch::orderByDesc('id')
            ->limit(20)
            ->get();

        return ApiResponse::success($batches, 'Payment batch history');
    }
}
