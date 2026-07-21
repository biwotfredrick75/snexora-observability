<?php

namespace App\Jobs;

use App\Events\DashboardEvent;
use App\Models\CustomerPayment;
use App\Models\DebtorAllocation;
use App\Models\FarmerPayment;
use App\Models\FarmerPaymentBatch;
use App\Models\SalesInvoice;
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
    // Matches CustomerPaymentController::TYPE_PAYMENT — the invoice
    // settlement wash entries below belong to the CustomerPayment
    // transaction they're posted under, not the farmer payment one.
    const GL_TYPE_CUSTOMER_PAYMENT = 12;
    const DEFAULT_FARMERS_PAYABLE = '201030';
    const DEFAULT_BANK_ACCOUNT    = '100010';
    const DEFAULT_RECEIVABLE_ACCOUNT = '104018';

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
        $receivableAcc     = $glSettings?->receivable_account      ?? self::DEFAULT_RECEIVABLE_ACCOUNT;

        $startDate = sprintf('%04d-%02d-01', $batch->year, $batch->month);
        $endDate   = date('Y-m-t', strtotime($startDate));
        $datePaid  = $batch->date_paid->format('Y-m-d');

        // The term selected when the batch was initiated governs both which
        // farmers are included and how they get paid:
        //  - no term selected, or term type = end_of_month → everyone (the
        //    standard main payroll run), full settlement.
        //  - any other real term selected → filter to that term's farmers,
        //    full settlement (unchanged behaviour).
        //  - term type = other → filter to that term's farmers, but post as
        //    an advance instead of a settlement; deductions/invoices/ESP are
        //    left outstanding for the next full settlement run to net off,
        //    which already recovers advances via $advancesMap below.
        $termType = $batch->payment_term_id
            ? DB::table('farmer_payment_terms')->where('id', $batch->payment_term_id)->value('type')
            : null;
        $isAdvanceRun = $termType === 'other';

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
        if ($batch->payment_term_id && $termType !== 'end_of_month') {
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
            $this->broadcastBatchDone($batch);
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
        $postedTransNos   = [];
        // Same, for CustomerPayments created while settling sales invoices —
        // these live outside the farmer-payment trans_no, so they need their
        // own rollback list.
        $postedPaymentIds = [];

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

            // Farmer name/member number for GL narration — so a voucher
            // reads as "Farmer payment FPY-... | Jane Doe (MBR-001234) |
            // Jul 2026" instead of just the batch reference.
            $farmerInfo = DB::table('farmers')
                ->whereIn('id', $chunk)
                ->select('id', 'full_name', 'member_no')
                ->get()
                ->keyBy('id');

            $deductions = DB::table('farmer_checkoff_entries')
                ->whereIn('farmer_id', $chunk)
                ->where('month', $batch->month)
                ->where('year', $batch->year)
                ->where('deducted', false)
                ->select('id', 'farmer_id', 'service_id', 'amount')
                ->get()
                ->groupBy('farmer_id');

            // Advances paid to farmers this period (weekly/biweekly payments
            // with type=advance) not yet recovered by an earlier settlement
            // — without this, a recovered advance keeps showing as
            // outstanding on every later run indefinitely.
            $advances = DB::table('farmer_payments')
                ->whereIn('farmer_id', $chunk)
                ->where('type', 'advance')
                ->where('recovered', false)
                ->whereBetween('date_paid', [$startDate, $endDate])
                ->select('id', 'farmer_id', 'amount_payment')
                ->get()
                ->groupBy('farmer_id');
            $advancesMap = $advances->map(fn ($rows) => $rows->sum('amount_payment'));

            // Direct invoices placed to farmers this period — excludes any
            // already settled by an earlier batch run (see settlement update
            // after posting below).
            $invoicesMap = DB::table('farmer_direct_invoices')
                ->whereIn('farmer_id', $chunk)
                ->where('status', 'placed')
                ->where('settled', false)
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

            // Regular Sales module invoices against a customer linked to this
            // farmer (customers.farmer_no) — a second "sales to farmers"
            // channel separate from farmer_direct_invoices above (e.g. a
            // farmer buying at a company shop/POS as a normal customer).
            // Outstanding = amount_total minus whatever is already allocated
            // via debtor_allocations — status stays 'placed' even once fully
            // allocated (same as CustomerPaymentController), so without this
            // an already-settled invoice would get deducted a second time on
            // the next run.
            $salesInvoiceMap = DB::table('sales_invoices as si')
                ->join('customers as c', 'c.debtor_no', '=', 'si.debtor_no')
                ->join('farmers as f2', 'f2.farmer_no', '=', 'c.farmer_no')
                ->leftJoin('debtor_allocations as da', 'da.inv_id', '=', 'si.id')
                ->whereIn('f2.id', $chunk)
                ->where('si.status', 'placed')
                ->whereBetween('si.invoice_date', [$startDate, $endDate])
                ->groupBy('f2.id', 'si.id', 'si.amount_total')
                ->selectRaw('f2.id AS farmer_id, si.amount_total - COALESCE(SUM(da.amount), 0) AS outstanding')
                ->get()
                ->groupBy('farmer_id')
                ->map(fn ($rows) => $rows->sum(fn ($r) => max(0, (float) $r->outstanding)));

            foreach ($chunk as $farmerId) {
                $grossAmount  = (float) ($milkData[$farmerId] ?? 0);
                if ($grossAmount <= 0) { $processed++; continue; }

                $advanceAmount    = (float) ($advancesMap[$farmerId] ?? 0);
                $advanceIds       = $advances->get($farmerId, collect())->pluck('id')->toArray();
                $invoiceAmount    = (float) ($invoicesMap[$farmerId] ?? 0);
                $espAmount        = (float) ($espMap[$farmerId] ?? 0);
                $carryFwdAmount   = (float) ($carryForwardMap[$farmerId] ?? 0);
                $salesInvAmount   = (float) ($salesInvoiceMap[$farmerId] ?? 0);
                $farmerDeductions = $deductions->get($farmerId, collect());
                $deductMap = [];
                $totalDed  = 0;
                $deductedEntryIds = [];
                foreach ($services as $svc) {
                    $entry  = $farmerDeductions->firstWhere('service_id', $svc->id);
                    $amount = $entry ? (float) $entry->amount : 0.0;
                    $deductMap[$svc->id] = ['amount' => $amount, 'gl_account' => $svc->gl_account ?? null];
                    $totalDed += $amount;
                    if ($entry) { $deductedEntryIds[] = $entry->id; }
                }

                $rawNet     = $grossAmount - $advanceAmount - $totalDed - $invoiceAmount - $espAmount - $carryFwdAmount - $salesInvAmount;
                $deficit    = $rawNet < 0 ? round(abs($rawNet), 4) : 0.0;
                $netPayment = max(0.0, $rawNet);

                // Debtor account this farmer settles sales invoices against,
                // if they're also registered as a regular Sales customer.
                $debtorNo = $salesInvAmount > 0
                    ? DB::table('farmers as fx')
                        ->join('customers as cx', 'cx.farmer_no', '=', 'fx.farmer_no')
                        ->where('fx.id', $farmerId)
                        ->value('cx.debtor_no')
                    : null;

                $farmerName = $farmerInfo[$farmerId]->full_name ?? '';
                $memberNo   = $farmerInfo[$farmerId]->member_no ?? '';

                // ── Attempt GL posting — any failure rolls back the entire batch ──
                try {
                    $transNo   = null;
                    $paymentId = null;
                    DB::transaction(function () use (
                        $farmerId, $grossAmount, $advanceAmount, $advanceIds, $invoiceAmount, $espAmount, $carryFwdAmount, $salesInvAmount,
                        $netPayment, $deficit, $totalDed, $debtorNo, $farmerName, $memberNo,
                        $deductMap, $deductedEntryIds, $batch, $datePaid, $isAdvanceRun,
                        $farmersPayableAcc, $bankAcc, $receivableAcc,
                        $nextMonth, $nextYear,
                        &$transNo, &$paymentId
                    ) {
                        $transNo = $this->nextTransNo();
                        $ref  = $batch->reference . '-F' . $farmerId;
                        $narr = 'Farmer payment ' . $batch->reference . ' | '
                              . trim($farmerName . ' (' . $memberNo . ')') . ' | '
                              . date('M', mktime(0, 0, 0, $batch->month, 1)) . ' ' . $batch->year;

                        // Term type = other → pay out the deduction-aware
                        // available balance ($netPayment) as an advance
                        // instead of a full settlement. Nothing is marked
                        // settled/deducted here — the next full settlement
                        // run recomputes everything and recovers this via
                        // the existing $advancesMap mechanism below.
                        if ($isAdvanceRun) {
                            if ($netPayment > 0) {
                                $this->glPost($transNo, $datePaid, $farmersPayableAcc,
                                              $netPayment, $ref, $narr . ' [advance]', $batch->created_by ?? '');
                                $this->glPost($transNo, $datePaid, $bankAcc,
                                              -$netPayment, $ref, $narr . ' [advance]', $batch->created_by ?? '');

                                FarmerPayment::create([
                                    'farmer_id'      => $farmerId,
                                    'from_account'   => $bankAcc,
                                    'date_paid'      => $datePaid,
                                    'reference'      => $batch->reference,
                                    'type'           => 'advance',
                                    'amount_payment' => $netPayment,
                                    'memo'           => 'Auto advance — ' . $narr,
                                    'created_by'     => $batch->created_by ?? 'system',
                                    'gl_trans_no'    => $transNo,
                                    'gl_type'        => self::GL_TYPE_FARMER_PAYMENT,
                                ]);
                            }
                            return;
                        }

                        // DR Farmers Payable Control (full gross milk value)
                        $this->glPost($transNo, $datePaid, $farmersPayableAcc,
                                      $grossAmount, $ref, $narr, $batch->created_by ?? '');

                        // CR Bank — advances already disbursed earlier in the
                        // period, then mark them recovered so they don't keep
                        // showing as outstanding on every later run.
                        if ($advanceAmount > 0) {
                            $this->glPost($transNo, $datePaid, $bankAcc,
                                          -$advanceAmount, $ref, $narr . ' [advance recovery]', $batch->created_by ?? '');

                            if (!empty($advanceIds)) {
                                DB::table('farmer_payments')
                                    ->whereIn('id', $advanceIds)
                                    ->update([
                                        'recovered'     => true,
                                        'recovered_at'  => $datePaid,
                                        'recovered_ref' => $batch->reference,
                                        'updated_at'    => now(),
                                    ]);
                            }
                        }

                        // CR Bank — direct invoice recovery, then mark those
                        // specific invoices settled so they can't be picked up
                        // again in a later re-run and don't sit looking unpaid.
                        if ($invoiceAmount > 0) {
                            $this->glPost($transNo, $datePaid, $bankAcc,
                                          -$invoiceAmount, $ref, $narr . ' [invoice recovery]', $batch->created_by ?? '');

                            DB::table('farmer_direct_invoices')
                                ->where('farmer_id', $farmerId)
                                ->where('status', 'placed')
                                ->where('settled', false)
                                ->update([
                                    'settled'     => true,
                                    'settled_at'  => $datePaid,
                                    'settled_ref' => $batch->reference,
                                    'updated_at'  => now(),
                                ]);
                        }

                        // CR Bank — regular sales invoice recovery (farmer
                        // bought as a normal customer). This side is a wash:
                        // the CR Bank here and the DR Bank in the customer
                        // payment posting below cancel out, since no cash
                        // actually moved — it's an internal transfer from
                        // what's owed to the farmer against what they owe as
                        // a customer. Also creates a real CustomerPayment +
                        // DebtorAllocation trail (FIFO, oldest invoice first —
                        // same algorithm as CustomerPaymentController::allocate())
                        // so the specific invoices actually show as settled
                        // rather than just a number silently subtracted here.
                        if ($salesInvAmount > 0 && $debtorNo) {
                            $this->glPost($transNo, $datePaid, $bankAcc,
                                          -$salesInvAmount, $ref, $narr . ' [sales invoice recovery]', $batch->created_by ?? '');

                            $payment = CustomerPayment::create([
                                'payment_no'          => 'TEMP-' . uniqid(),
                                'debtor_no'          => $debtorNo,
                                'payment_date'        => $datePaid,
                                'bank_account_code'   => $bankAcc,
                                'payment_type'        => 'farmer_deduction',
                                'amount'              => $salesInvAmount,
                                'discount_amount'     => 0,
                                'bank_charge'         => 0,
                                'allocated_amount'    => 0,
                                'unallocated_amount'  => $salesInvAmount,
                                'reference'           => $ref,
                                'memo'                => 'Settled via farmer milk payment ' . $batch->reference,
                                'status'              => 'posted',
                                'created_by'          => $batch->created_by ?? 'system',
                            ]);
                            $payment->update(['payment_no' => CustomerPayment::nextPaymentNo()]);
                            $paymentId = $payment->id;

                            $invoicesToSettle = SalesInvoice::where('debtor_no', $debtorNo)
                                ->where('status', 'placed')
                                ->orderBy('invoice_date')
                                ->orderBy('id')
                                ->lockForUpdate()
                                ->get();

                            $remaining    = $salesInvAmount;
                            $totalApplied = 0.0;
                            foreach ($invoicesToSettle as $inv) {
                                if ($remaining <= 0.005) break;
                                $alreadyAlloc = (float) DebtorAllocation::where('inv_id', $inv->id)->sum('amount');
                                $leftToAlloc  = round((float) $inv->amount_total - $alreadyAlloc, 2);
                                if ($leftToAlloc <= 0) continue;

                                $use = min($remaining, $leftToAlloc);
                                DebtorAllocation::create([
                                    'debtor_no'      => $debtorNo,
                                    'source_type'    => 'payment',
                                    'source_id'      => $payment->id,
                                    'source_no'      => $payment->payment_no,
                                    'inv_id'         => $inv->id,
                                    'inv_no'         => $inv->inv_no,
                                    'amount'         => round($use, 2),
                                    'allocated_date' => $datePaid,
                                    'created_by'     => $batch->created_by ?? 'system',
                                ]);
                                $remaining    -= $use;
                                $totalApplied += $use;
                            }

                            $payment->allocated_amount   = round($totalApplied, 2);
                            $payment->unallocated_amount = round($salesInvAmount - $totalApplied, 2);
                            $payment->save();

                            // DR Bank / CR Debtors — the customer-payment side
                            // of the wash described above. Posted under the
                            // CustomerPayment's own trans_no/type (matching
                            // CustomerPaymentController), not the farmer
                            // payment's, so it shows up correctly wherever
                            // customer payment GL entries are looked up.
                            $this->glPost($payment->id, $datePaid, $bankAcc,
                                          $salesInvAmount, $payment->payment_no, $narr . ' [invoice settlement receipt]',
                                          $batch->created_by ?? '', self::GL_TYPE_CUSTOMER_PAYMENT);
                            $this->glPost($payment->id, $datePaid, $receivableAcc,
                                          -$salesInvAmount, $payment->payment_no, $narr . ' [invoice settlement]',
                                          $batch->created_by ?? '', self::GL_TYPE_CUSTOMER_PAYMENT);
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

                        // Mark these checkoff entries deducted so a re-run/
                        // repost for the same period never subtracts them
                        // again (there's no per-entry outstanding-balance
                        // concept here like invoices have, so this flag is
                        // the only guard).
                        if (!empty($deductedEntryIds)) {
                            DB::table('farmer_checkoff_entries')
                                ->whereIn('id', $deductedEntryIds)
                                ->update([
                                    'deducted'     => true,
                                    'deducted_at'  => $datePaid,
                                    'deducted_ref' => $batch->reference,
                                    'updated_at'   => now(),
                                ]);
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
                    if ($paymentId) { $postedPaymentIds[] = $paymentId; }
                    $totalGross += $grossAmount;
                    $totalNet   += $netPayment;
                    if ($isAdvanceRun) {
                        // This run's "advances" total is what was just
                        // disbursed here, not any pre-existing advance
                        // total already counted by an earlier batch.
                        // Nothing was actually deducted this run.
                        $totalAdvances += $netPayment;
                    } else {
                        $totalAdvances += $advanceAmount;
                        // total_deductions = everything netted off gross OTHER than
                        // advances, which already has its own tracked total above —
                        // previously only checkoff services ($totalDed) were counted
                        // here, silently excluding invoices/ESP/carry-forward/sales.
                        $totalDeduct += $totalDed + $invoiceAmount + $espAmount + $carryFwdAmount + $salesInvAmount;
                    }
                    $processed++;

                } catch (\Throwable $e) {
                    // ── Hard stop: roll back every GL entry posted so far ──────────
                    $this->rollbackPosted($postedTransNos, $postedPaymentIds);

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
            // Advance runs (term type=other) don't close the month — their
            // whole point is to be followed later by the real settlement.
            // Only a full settlement locks the entire month against any
            // further processing.
            'locked'       => !$isAdvanceRun,
            'completed_at' => now(),
        ]);
        $this->broadcastBatchDone($batch);

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
    private function rollbackPosted(array $transNos, array $paymentIds = []): void
    {
        if (!empty($transNos)) {
            DB::table('gld_transactions')
                ->where('type', self::GL_TYPE_FARMER_PAYMENT)
                ->whereIn('trans_no', $transNos)
                ->delete();
        }

        // CustomerPayments (+ their allocations and GL entries) created while
        // settling sales invoices — these sit outside the farmer-payment
        // trans_nos above, so they need their own cleanup. The settled
        // invoices' own `status` is untouched by allocation (same as
        // CustomerPaymentController), so nothing needs unmarking there.
        if (!empty($paymentIds)) {
            DB::table('gld_transactions')
                ->where('type', self::GL_TYPE_CUSTOMER_PAYMENT)
                ->whereIn('trans_no', $paymentIds)
                ->delete();
            DB::table('debtor_allocations')
                ->where('source_type', 'payment')
                ->whereIn('source_id', $paymentIds)
                ->delete();
            DB::table('customer_payments')->whereIn('id', $paymentIds)->delete();
        }

        // farmer_direct_invoices marked settled by this batch also need
        // reverting, or they'd look paid despite the payment being rolled back.
        DB::table('farmer_direct_invoices')
            ->where('settled_ref', $this->batchReference())
            ->update(['settled' => false, 'settled_at' => null, 'settled_ref' => null]);

        // Same gap already existed for ESP sales marked deducted by this batch.
        DB::table('esp_farmer_sales')
            ->where('party_deducted_ref', $this->batchReference())
            ->update(['party_deducted' => false, 'party_deducted_at' => null, 'party_deducted_ref' => null]);

        // Same gap for checkoff entries (incl. SACCO loan repayments) marked
        // deducted by this batch.
        DB::table('farmer_checkoff_entries')
            ->where('deducted_ref', $this->batchReference())
            ->update(['deducted' => false, 'deducted_at' => null, 'deducted_ref' => null]);

        // Same gap for advances marked recovered by this batch.
        DB::table('farmer_payments')
            ->where('recovered_ref', $this->batchReference())
            ->update(['recovered' => false, 'recovered_at' => null, 'recovered_ref' => null]);

        // Advance-run farmer_payments rows (type=other term) — their GL
        // entries are already removed above via $transNos since they were
        // posted under the same farmer-payment trans_no.
        DB::table('farmer_payments')
            ->where('reference', $this->batchReference())
            ->where('type', 'advance')
            ->delete();

        Log::info("FarmerPaymentBatch #{$this->batchId}: rolled back " . count($transNos) . " GL trans_nos, " . count($paymentIds) . " customer payments.");
    }

    private function batchReference(): string
    {
        return FarmerPaymentBatch::find($this->batchId)?->reference ?? '';
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
        float $amount, string $ref, string $narration, string $createdBy,
        int $type = self::GL_TYPE_FARMER_PAYMENT
    ): void {
        DB::table('gld_transactions')->insert([
            'trans_no'     => $transNo,
            'type'         => $type,
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

    private function broadcastBatchDone(FarmerPaymentBatch $batch): void
    {
        try {
            broadcast(new DashboardEvent('payments', 'farmer_payment_batch_posted', [
                'batch_id'      => $batch->id,
                'total_farmers' => $batch->total_farmers,
                'total_net'     => $batch->total_net,
            ]));
        } catch (\Throwable $e) {
            Log::error('Dashboard broadcast failed: ' . $e->getMessage());
        }
    }
}
