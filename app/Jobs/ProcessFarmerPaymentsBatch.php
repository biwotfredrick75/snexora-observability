<?php

namespace App\Jobs;

use App\Models\FarmerPaymentBatch;
use App\Services\Blockchain\BlockchainService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessFarmerPaymentsBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;  // 1 hour max
    public int $tries   = 3;     // auto-retry up to 3 times
    public int $backoff = 60;    // 60s between retries

    const GL_TYPE_FARMER_PAYMENT  = 50;
    const DEFAULT_FARMERS_PAYABLE = '201030';
    const DEFAULT_BANK_ACCOUNT    = '100010';

    public function __construct(public readonly int $batchId) {}

    public function handle(): void
    {
        $batch = FarmerPaymentBatch::findOrFail($this->batchId);
        $batch->update([
            'status'      => 'processing',
            'started_at'  => now(),
            'failed_count'   => 0,
            'error_message'  => null,
        ]);

        try {
            $this->runBatch($batch);
        } catch (\Throwable $e) {
            $attempt = $this->attempts();
            $msg = $e->getMessage();
            Log::error("FarmerPaymentBatch #{$this->batchId} attempt {$attempt} failed: {$msg}");

            $batch->update([
                'status'        => 'failed',
                'error_message' => "[Attempt {$attempt}/{$this->tries}] {$msg}",
                'completed_at'  => now(),
            ]);

            throw $e;  // re-throw so Laravel handles retry / failed_jobs
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function runBatch(FarmerPaymentBatch $batch): void
    {
        $glSettings = DB::table('gl_settings')->first();
        $farmersPayableAcc = $glSettings?->farmers_payable_account ?? self::DEFAULT_FARMERS_PAYABLE;
        $bankAcc           = $glSettings?->farmers_bank_account    ?? self::DEFAULT_BANK_ACCOUNT;

        $startDate = sprintf('%04d-%02d-01', $batch->year, $batch->month);
        $endDate   = date('Y-m-t', strtotime($startDate));
        $datePaid  = $batch->date_paid->format('Y-m-d');

        $services = DB::table('checkoff_services')
            ->where('active', true)
            ->get(['id', 'service_name', 'service_type', 'gl_account']);

        // Build farmer ID list
        $farmerQuery = DB::table('milk_purchase_items as mpi')
            ->join('milk_purchases as mp', 'mp.id', '=', 'mpi.purchase_id')
            ->whereBetween('mp.invoice_date', [$startDate, $endDate])
            ->where('mp.status', '!=', 'cancelled');

        if ($batch->route_id) {
            $farmerQuery->where('mp.route_id', $batch->route_id);
        }
        if ($batch->payment_term_id) {
            $farmerQuery->join('farmers as f2', 'f2.id', '=', 'mpi.farmer_id')
                        ->where('f2.payment_terms_id', $batch->payment_term_id);
        }

        $farmerIds = $farmerQuery
            ->select('mpi.farmer_id')
            ->distinct()
            ->pluck('farmer_id')
            ->toArray();

        $total = count($farmerIds);
        $batch->update(['total_farmers' => $total]);

        if ($total === 0) {
            $batch->update(['status' => 'done', 'completed_at' => now()]);
            return;
        }

        // ── Compute next period for carry-forward writes ──────────────────────
        $nextMonth = $batch->month == 12 ? 1  : $batch->month + 1;
        $nextYear  = $batch->month == 12 ? $batch->year + 1 : $batch->year;

        $processed      = 0;
        $totalGross     = 0;
        $totalAdvances  = 0;
        $totalDeduct    = 0;
        $totalNet       = 0;
        $chunkSize      = 50;

        // Track all trans_nos posted so far — used for full rollback on any failure
        $postedTransNos = [];

        foreach (array_chunk($farmerIds, $chunkSize) as $chunk) {
            $milkData = DB::table('milk_purchase_items as mpi')
                ->join('milk_purchases as mp', 'mp.id', '=', 'mpi.purchase_id')
                ->join('farmers as f', 'f.id', '=', 'mpi.farmer_id')
                ->whereBetween('mp.invoice_date', [$startDate, $endDate])
                ->where('mp.status', '!=', 'cancelled')
                ->whereIn('mpi.farmer_id', $chunk)
                ->selectRaw('mpi.farmer_id, SUM(mpi.total_price) as gross_amount')
                ->groupBy('mpi.farmer_id')
                ->pluck('gross_amount', 'farmer_id');

            $deductions = DB::table('farmer_checkoff_entries')
                ->whereIn('farmer_id', $chunk)
                ->where('month', $batch->month)
                ->where('year', $batch->year)
                ->select('farmer_id', 'service_id', 'amount')
                ->get()
                ->groupBy('farmer_id');

            // Advances paid to farmers this period (weekly/biweekly payments with type=advance)
            $advancesMap = DB::table('farmer_payments')
                ->whereIn('farmer_id', $chunk)
                ->where('type', 'advance')
                ->whereBetween('date_paid', [$startDate, $endDate])
                ->selectRaw('farmer_id, SUM(amount_payment) AS advance_total')
                ->groupBy('farmer_id')
                ->pluck('advance_total', 'farmer_id');

            // Direct invoices placed to farmers this period
            $invoicesMap = DB::table('farmer_direct_invoices')
                ->whereIn('farmer_id', $chunk)
                ->where('status', 'placed')
                ->whereBetween('invoice_date', [$startDate, $endDate])
                ->selectRaw('farmer_id, SUM(amount_total) AS invoice_total')
                ->groupBy('farmer_id')
                ->pluck('invoice_total', 'farmer_id');

            // External agrovet/service-provider (ESP) sales not yet deducted from this farmer
            $espMap = DB::table('esp_farmer_sales')
                ->where('party_type', 'farmer')
                ->whereIn('party_id', $chunk)
                ->where('party_deducted', false)
                ->where('status', '!=', 'void')
                ->selectRaw('party_id AS farmer_id, SUM(total_amount) AS esp_total')
                ->groupBy('party_id')
                ->pluck('esp_total', 'farmer_id');

            // Carry-forward debt from previous period
            $carryForwardMap = DB::table('farmer_carry_forwards')
                ->whereIn('farmer_id', $chunk)
                ->where('to_month', $batch->month)
                ->where('to_year', $batch->year)
                ->pluck('amount', 'farmer_id');

            foreach ($chunk as $farmerId) {
                $grossAmount  = (float) ($milkData[$farmerId] ?? 0);
                if ($grossAmount <= 0) { $processed++; continue; }

                $advanceAmount    = (float) ($advancesMap[$farmerId] ?? 0);
                $invoiceAmount    = (float) ($invoicesMap[$farmerId] ?? 0);
                $espAmount        = (float) ($espMap[$farmerId] ?? 0);
                $carryFwdAmount   = (float) ($carryForwardMap[$farmerId] ?? 0);
                $farmerDeductions = $deductions->get($farmerId, collect());
                $deductMap = [];
                $totalDed  = 0;
                foreach ($services as $svc) {
                    $entry  = $farmerDeductions->firstWhere('service_id', $svc->id);
                    $amount = $entry ? (float) $entry->amount : 0.0;
                    $deductMap[$svc->id] = ['amount' => $amount, 'gl_account' => $svc->gl_account ?? null];
                    $totalDed += $amount;
                }

                $rawNet     = $grossAmount - $advanceAmount - $totalDed - $invoiceAmount - $espAmount - $carryFwdAmount;
                $deficit    = $rawNet < 0 ? round(abs($rawNet), 4) : 0.0;
                $netPayment = max(0.0, $rawNet);

                // ── Attempt GL posting — any failure rolls back the entire batch ──
                try {
                    $transNo = null;
                    DB::transaction(function () use (
                        $farmerId, $grossAmount, $advanceAmount, $invoiceAmount, $espAmount, $carryFwdAmount,
                        $netPayment, $deficit, $totalDed,
                        $deductMap, $batch, $datePaid,
                        $farmersPayableAcc, $bankAcc,
                        $nextMonth, $nextYear,
                        &$transNo
                    ) {
                        $transNo = $this->nextTransNo();
                        $ref  = $batch->reference . '-F' . $farmerId;
                        $narr = 'Farmer payment ' . $batch->reference . ' | '
                              . date('M', mktime(0, 0, 0, $batch->month, 1)) . ' ' . $batch->year;

                        // DR Farmers Payable Control (full gross milk value)
                        $this->glPost($transNo, $datePaid, $farmersPayableAcc,
                                      $grossAmount, $ref, $narr, $batch->created_by ?? '');

                        // CR Bank — advances already disbursed earlier in the period
                        if ($advanceAmount > 0) {
                            $this->glPost($transNo, $datePaid, $bankAcc,
                                          -$advanceAmount, $ref, $narr . ' [advance recovery]', $batch->created_by ?? '');
                        }

                        // CR Bank — direct invoice recovery
                        if ($invoiceAmount > 0) {
                            $this->glPost($transNo, $datePaid, $bankAcc,
                                          -$invoiceAmount, $ref, $narr . ' [invoice recovery]', $batch->created_by ?? '');
                        }

                        // CR Bank — carry-forward debt recovery
                        if ($carryFwdAmount > 0) {
                            $this->glPost($transNo, $datePaid, $bankAcc,
                                          -$carryFwdAmount, $ref, $narr . ' [carry-fwd recovery]', $batch->created_by ?? '');
                        }

                        // CR Bank — external agrovet/service-provider (ESP) sale recovery
                        if ($espAmount > 0) {
                            $this->glPost($transNo, $datePaid, $bankAcc,
                                          -$espAmount, $ref, $narr . ' [esp recovery]', $batch->created_by ?? '');

                            DB::table('esp_farmer_sales')
                                ->where('party_type', 'farmer')
                                ->where('party_id', $farmerId)
                                ->where('party_deducted', false)
                                ->where('status', '!=', 'void')
                                ->update([
                                    'party_deducted'     => true,
                                    'party_deducted_at'  => $datePaid,
                                    'party_deducted_ref' => $batch->reference,
                                    'updated_at'         => now(),
                                ]);
                        }

                        // CR Bank — remaining net cash out at payroll
                        if ($netPayment > 0) {
                            $this->glPost($transNo, $datePaid, $bankAcc,
                                          -$netPayment, $ref, $narr, $batch->created_by ?? '');
                        }

                        // CR each checkoff deduction account
                        foreach ($deductMap as $ded) {
                            if ($ded['amount'] > 0 && !empty($ded['gl_account'])) {
                                $this->glPost($transNo, $datePaid, $ded['gl_account'],
                                              -$ded['amount'], $ref, $narr, $batch->created_by ?? '');
                            }
                        }

                        // CR unallocated deductions (no GL account set) → bank
                        $unallocated = $totalDed - array_sum(array_map(
                            fn($d) => (!empty($d['gl_account']) ? $d['amount'] : 0), $deductMap
                        ));
                        if ($unallocated > 0.005) {
                            $this->glPost($transNo, $datePaid, $bankAcc,
                                          -$unallocated, $ref, $narr . ' [misc deduct]', $batch->created_by ?? '');
                        }

                        // ── Write/update carry-forward for next period if deficit ──
                        if ($deficit > 0) {
                            DB::table('farmer_carry_forwards')->upsert(
                                [[
                                    'farmer_id'     => $farmerId,
                                    'to_month'      => $nextMonth,
                                    'to_year'       => $nextYear,
                                    'amount'        => $deficit,
                                    'notes'         => 'Auto from batch ' . $batch->reference,
                                    'from_batch_id' => $batch->id,
                                    'created_by'    => $batch->created_by ?? 'system',
                                    'created_at'    => now(),
                                    'updated_at'    => now(),
                                ]],
                                ['farmer_id', 'to_month', 'to_year'],
                                ['amount', 'notes', 'from_batch_id', 'updated_at']
                            );
                        } else {
                            // Clear any previously written carry-forward for next period
                            // (re-run scenario: if deficit was fixed, remove the record)
                            DB::table('farmer_carry_forwards')
                                ->where('farmer_id', $farmerId)
                                ->where('to_month', $nextMonth)
                                ->where('to_year', $nextYear)
                                ->where('from_batch_id', $batch->id)
                                ->delete();
                        }
                    });

                    $postedTransNos[] = $transNo;
                    $totalGross    += $grossAmount;
                    $totalAdvances += $advanceAmount;
                    $totalDeduct   += $totalDed;
                    $totalNet      += $netPayment;
                    $processed++;

                } catch (\Throwable $e) {
                    // ── Hard stop: roll back every GL entry posted so far ──────────
                    $this->rollbackPosted($postedTransNos);

                    $batch->update([
                        'processed_count'  => 0,
                        'failed_count'     => 1,
                        'total_gross'      => 0,
                        'total_advances'   => 0,
                        'total_deductions' => 0,
                        'total_net'        => 0,
                    ]);

                    throw new \RuntimeException(
                        "Farmer #{$farmerId} GL post failed — entire batch rolled back. Error: " . $e->getMessage(),
                        0, $e
                    );
                }
            } // end chunk farmers

            // Progress update after each chunk
            $batch->update([
                'processed_count'  => $processed,
                'failed_count'     => 0,
                'total_gross'      => $totalGross,
                'total_advances'   => $totalAdvances,
                'total_deductions' => $totalDeduct,
                'total_net'        => $totalNet,
            ]);
        } // end chunks

        $batch->update([
            'status'       => 'done',
            'completed_at' => now(),
        ]);

        // ── Anchor the completed batch on-chain (fire-and-forget) ────────────
        try {
            $period = sprintf('%04d-%02d', $batch->year, $batch->month);
            (new BlockchainService())->anchorFarmerPaymentBatch(
                batchId:        $batch->id,
                reference:      $batch->reference,
                period:         $period,
                totalGross:     (float) $batch->total_gross,
                totalDeductions:(float) $batch->total_deductions,
                totalNet:       (float) $batch->total_net,
                farmerCount:    (int)   $batch->total_farmers,
                preparedBy:     $batch->created_by ?? 'system',
            );
        } catch (\Throwable $e) {
            Log::error("FarmerPaymentBatch #{$this->batchId}: blockchain anchor failed: " . $e->getMessage());
        }
    }

    // ── Delete all GL entries posted under the given trans_nos ───────────────
    private function rollbackPosted(array $transNos): void
    {
        if (empty($transNos)) return;

        DB::table('gld_transactions')
            ->where('type', self::GL_TYPE_FARMER_PAYMENT)
            ->whereIn('trans_no', $transNos)
            ->delete();

        Log::info("FarmerPaymentBatch #{$this->batchId}: rolled back " . count($transNos) . " GL trans_nos.");
    }

    // ── Atomic next trans_no ─────────────────────────────────────────────────
    private function nextTransNo(): int
    {
        return DB::transaction(function () {
            $max = DB::table('gld_transactions')
                ->where('type', self::GL_TYPE_FARMER_PAYMENT)
                ->lockForUpdate()
                ->max('trans_no') ?? 0;
            return $max + 1;
        });
    }

    // ── Insert one GL debit/credit line ──────────────────────────────────────
    private function glPost(
        int $transNo, string $date, string $account,
        float $amount, string $ref, string $narration, string $createdBy
    ): void {
        DB::table('gld_transactions')->insert([
            'trans_no'     => $transNo,
            'type'         => self::GL_TYPE_FARMER_PAYMENT,
            'tran_date'    => $date,
            'account_code' => $account,
            'reference'    => $ref,
            'narration'    => $narration,
            'amount'       => round($amount, 4),
            'created_by'   => $createdBy,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }
}
