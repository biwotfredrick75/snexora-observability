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
    // ── Check if a batch already exists for this period ───────────────────────
    public function checkPeriod(Request $request): JsonResponse
    {
        $request->validate([
            'month' => 'required|integer|between:1,12',
            'year'  => 'required|integer|min:2000|max:2100',
        ]);

        $batch = FarmerPaymentBatch::where('month', $request->month)
            ->where('year',  $request->year)
            ->whereIn('status', ['done', 'processing'])
            ->latest()
            ->first();

        return ApiResponse::success([
            'already_processed' => $batch !== null,
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

        // Prevent double-posting
        $existing = FarmerPaymentBatch::where('month', $request->month)
            ->where('year', $request->year)
            ->whereIn('status', ['processing', 'pending'])
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
            'payment_term_id' => $request->payment_term_id ?: null,
            'route_id'        => $request->route_id        ?: null,
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
