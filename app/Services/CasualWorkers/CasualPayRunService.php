<?php

namespace App\Services\CasualWorkers;

use App\Models\CasualEarningType;
use App\Models\CasualPayPosting;
use App\Models\CasualWorkerAttendance;
use App\Models\CasualWorkerPayRun;
use App\Models\CasualWorkerPayRunItem;
use App\Models\CasualWorkerPayRate;
use App\Models\CasualWorker;
use Illuminate\Support\Facades\DB;

class CasualPayRunService
{
    /**
     * Full generation (non-chunked). Upserts items and postings; manual adjustments preserved.
     */
    public function generate(CasualWorkerPayRun $run): CasualWorkerPayRun
    {
        DB::transaction(function () use ($run) {
            // Delete all postings — auto values will be recreated; manual overrides re-apply after gen
            $run->postings()->delete();

            $rows = CasualWorkerAttendance::with('worker.trade')
                ->whereBetween('work_date', [$run->period_start, $run->period_end])
                ->whereIn('status', ['present', 'late', 'half_day'])
                ->get()
                ->groupBy('worker_id');

            $presentWorkerIds = $rows->keys()->all();
            $run->items()->whereNotIn('worker_id', $presentWorkerIds)->delete();

            $total = 0;
            foreach ($rows as $workerId => $attendances) {
                $total += $this->processWorker($run, $workerId, $attendances);
            }

            $run->update(['status' => 'generated', 'total_amount' => round($total, 2)]);
        });

        return $run->fresh(['items.worker.trade', 'postings.earningType.glAccount']);
    }

    /**
     * Chunked generation — call repeatedly until done === true.
     *
     * offset=0: wipes auto postings and marks run as draft.
     * Each call upserts items + auto postings for a batch of workers.
     * Final call: removes stale items and finalises.
     */
    public function generateChunk(CasualWorkerPayRun $run, int $offset, int $chunkSize = 4): array
    {
        $allWorkerIds = CasualWorkerAttendance::whereBetween('work_date', [$run->period_start, $run->period_end])
            ->whereIn('status', ['present', 'late', 'half_day'])
            ->orderBy('worker_id')
            ->distinct()
            ->pluck('worker_id');

        $total = $allWorkerIds->count();

        if ($offset === 0) {
            // Delete all postings so auto amounts are cleanly recomputed from scratch
            $run->postings()->delete();
            $run->update(['status' => 'draft']);
        }

        $chunk = $allWorkerIds->slice($offset, $chunkSize)->values();

        if ($chunk->isEmpty()) {
            $run->items()->whereNotIn('worker_id', $allWorkerIds->all())->delete();

            $totalPay = $run->items()->sum('net_pay');
            $run->update(['status' => 'generated', 'total_amount' => round($totalPay, 2)]);

            return [
                'done'        => true,
                'processed'   => $total,
                'total'       => $total,
                'next_offset' => $offset,
                'run'         => $run->fresh(['items.worker.trade', 'postings.earningType.glAccount']),
            ];
        }

        foreach ($chunk as $workerId) {
            $attendances = CasualWorkerAttendance::with('worker.trade')
                ->where('worker_id', $workerId)
                ->whereBetween('work_date', [$run->period_start, $run->period_end])
                ->whereIn('status', ['present', 'late', 'half_day'])
                ->get();

            $this->processWorker($run, $workerId, $attendances);
        }

        return [
            'done'        => false,
            'processed'   => min($offset + $chunk->count(), $total),
            'total'       => $total,
            'next_offset' => $offset + $chunkSize,
        ];
    }

    /**
     * Upsert one worker's pay item and write per-type auto postings.
     * All postings are deleted before generation, then recreated as is_auto=true.
     * Untyped manual_allowances / manual_deductions on the item are preserved.
     */
    private function processWorker(CasualWorkerPayRun $run, int $workerId, $attendances): float
    {
        $worker = $attendances->first()?->worker;
        if (!$worker || $worker->status !== 'active') return 0;

        $rate     = $this->resolveRate($worker, $run->period_end->toDateString());
        $rateType = $rate?->rate_type ?? 'daily';
        $baseRate = $rate?->rate      ?? 0;

        $daysWorked  = $attendances->sum(fn($a) => $a->status === 'half_day' ? 0.5 : 1);
        $hoursWorked = $attendances->sum('hours_worked');

        $basePay = $rateType === 'hourly'
            ? round($hoursWorked * $baseRate, 2)
            : round($daysWorked  * $baseRate, 2);

        $transportAllowance = round($daysWorked * ($rate?->transport_allowance ?? 0), 2);
        $mealAllowance      = round($daysWorked * ($rate?->meal_allowance      ?? 0), 2);

        $payWelfare     = $worker->trade?->pay_welfare ?? true;
        $autoEarnings   = 0.0;
        $autoDeductions = 0.0;

        // Auto-apply each active earning type and record a posting
        foreach ($this->activeEarningTypes() as $type) {
            if ($type->welfare_only && !$payWelfare) continue;

            $amount = $type->frequency === 'daily'
                ? round($type->default_amount * $daysWorked, 2)
                : (float)$type->default_amount;

            // Upsert on the unique key (pay_run_id, worker_id, earning_type_id)
            CasualPayPosting::updateOrCreate(
                ['pay_run_id' => $run->id, 'worker_id' => $workerId, 'earning_type_id' => $type->id],
                ['amount' => $amount, 'is_auto' => true]
            );

            if ($type->category === 'earning') {
                $autoEarnings += $amount;
            } else {
                $autoDeductions += $amount;
            }
        }

        // Load the existing item to read manual amounts and manual postings
        $item = CasualWorkerPayRunItem::firstOrNew([
            'pay_run_id' => $run->id,
            'worker_id'  => $workerId,
        ]);

        // Preserve untyped manual adjustments entered via the "Other Allow." / "Other Ded." inputs
        $manualAllowances = $item->exists ? (float)($item->manual_allowances ?? 0) : 0;
        $manualDeductions = $item->exists ? (float)($item->manual_deductions ?? 0) : 0;

        $otherAllowances = round($autoEarnings   + $manualAllowances, 2);
        $totalDeductions = round($autoDeductions + $manualDeductions, 2);
        $netPay          = round($basePay + $transportAllowance + $mealAllowance + $otherAllowances - $totalDeductions, 2);

        $item->fill([
            'days_worked'         => $daysWorked,
            'hours_worked'        => $hoursWorked,
            'base_rate'           => $baseRate,
            'base_pay'            => $basePay,
            'transport_allowance' => $transportAllowance,
            'meal_allowance'      => $mealAllowance,
            'other_allowances'    => $otherAllowances,
            'manual_allowances'   => $manualAllowances,
            'deductions'          => $totalDeductions,
            'manual_deductions'   => $manualDeductions,
            'net_pay'             => $netPay,
        ]);

        if (!$item->exists) {
            $item->payment_status = 'pending';
        }

        $item->save();

        return $netPay;
    }

    /** Cached within a request — avoids N+1 queries per worker within the same chunk. */
    private ?array $earningTypesCache = null;

    private function activeEarningTypes(): array
    {
        return $this->earningTypesCache ??= CasualEarningType::where('active', true)
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    public function approve(CasualWorkerPayRun $run, int $userId): CasualWorkerPayRun
    {
        if ($run->status === 'paid') {
            throw new \RuntimeException('Pay run is already paid.');
        }

        $run->update([
            'status'      => 'approved',
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);

        return $run->fresh(['items.worker']);
    }

    public function mpesaExportRows(CasualWorkerPayRun $run): array
    {
        return $run->items()
            ->with('worker')
            ->where('payment_status', 'pending')
            ->get()
            ->map(fn($item) => [
                $item->worker->mpesa_no ?? '',
                $item->worker->name,
                number_format($item->net_pay, 2, '.', ''),
                "Wages {$run->ref_no}",
            ])
            ->toArray();
    }

    private function resolveRate(CasualWorker $worker, string $date): ?CasualWorkerPayRate
    {
        $rate = CasualWorkerPayRate::where('worker_id', $worker->id)
            ->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->first();

        if (!$rate && $worker->trade_id) {
            $rate = CasualWorkerPayRate::whereNull('worker_id')
                ->where('trade_id', $worker->trade_id)
                ->where('effective_from', '<=', $date)
                ->orderByDesc('effective_from')
                ->first();
        }

        return $rate;
    }
}
