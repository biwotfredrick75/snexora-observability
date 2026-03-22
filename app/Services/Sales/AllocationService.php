<?php

namespace App\Services\Sales;

use App\Models\CreditNote;
use App\Models\CustomerPayment;
use App\Models\DebtorAllocation;
use App\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;

class AllocationService
{
    /**
     * Auto-allocate a placed credit note against its linked invoice.
     * Allocates the lesser of the CN amount and the invoice outstanding balance.
     * Returns null if the CN has no inv_id or the invoice is already fully settled.
     */
    public function allocateCreditNote(CreditNote $cn): ?DebtorAllocation
    {
        if (! $cn->inv_id) return null;

        $invoice = SalesInvoice::find($cn->inv_id);
        if (! $invoice) return null;

        $alreadyAllocated = DebtorAllocation::where('inv_id', $invoice->id)->sum('amount');
        $outstanding      = round($invoice->amount_total - $alreadyAllocated, 2);

        if ($outstanding <= 0) return null;

        // For write_off use write_off_amount; others use amount_total
        $cnAmount    = $cn->cn_type === 'write_off'
            ? (float) $cn->write_off_amount
            : (float) $cn->amount_total;

        $allocAmount = min($cnAmount, $outstanding);

        if ($allocAmount <= 0) return null;

        $cnDate = $cn->cn_date instanceof \Carbon\Carbon
            ? $cn->cn_date->toDateString()
            : $cn->cn_date;

        $alloc = DebtorAllocation::create([
            'debtor_no'      => $cn->debtor_no,
            'source_type'    => 'credit_note',
            'source_id'      => $cn->id,
            'source_no'      => $cn->cn_no,
            'inv_id'         => $invoice->id,
            'inv_no'         => $invoice->inv_no,
            'amount'         => $allocAmount,
            'allocated_date' => $cnDate,
            'created_by'     => auth()->user()?->user_id ?? 'system',
        ]);

        // Keep CN unallocated balance in sync
        $cn->unallocated_amount = max(0, round((float) $cn->unallocated_amount - $allocAmount, 2));
        $cn->allocated_amount   = round((float) $cn->allocated_amount   + $allocAmount, 2);
        $cn->save();

        return $alloc;
    }

    /**
     * Apply any unallocated customer payment balance to a newly placed invoice.
     * Payments are consumed FIFO (oldest first). Returns total amount allocated.
     */
    public function applyUnallocatedPayments(SalesInvoice $invoice, string $tranDate, string $createdBy): float
    {
        $alreadyAllocated = DebtorAllocation::where('inv_id', $invoice->id)->sum('amount');
        $outstanding      = round($invoice->amount_total - $alreadyAllocated, 2);

        if ($outstanding <= 0) return 0.0;

        $payments = CustomerPayment::where('debtor_no', $invoice->debtor_no)
            ->where('status', 'posted')
            ->where('unallocated_amount', '>', 0)
            ->orderBy('payment_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $totalApplied = 0.0;

        foreach ($payments as $payment) {
            if ($outstanding <= 0) break;

            $use = min(round((float) $payment->unallocated_amount, 2), $outstanding);
            if ($use <= 0) continue;

            DebtorAllocation::create([
                'debtor_no'      => $invoice->debtor_no,
                'source_type'    => 'payment',
                'source_id'      => $payment->id,
                'source_no'      => $payment->payment_no,
                'inv_id'         => $invoice->id,
                'inv_no'         => $invoice->inv_no,
                'amount'         => $use,
                'allocated_date' => $tranDate,
                'created_by'     => $createdBy,
            ]);

            $payment->unallocated_amount = round($payment->unallocated_amount - $use, 2);
            $payment->allocated_amount   = round($payment->allocated_amount   + $use, 2);
            $payment->save();

            $outstanding   -= $use;
            $totalApplied  += $use;
        }

        return round($totalApplied, 2);
    }

    /**
     * Apply any unallocated credit note balance to a newly placed invoice (FIFO).
     * Only credit notes for the same customer with unallocated_amount > 0 are used.
     * Returns total amount applied.
     */
    public function applyUnallocatedCreditNotes(SalesInvoice $invoice, string $tranDate, string $createdBy): float
    {
        $alreadyAllocated = DebtorAllocation::where('inv_id', $invoice->id)->sum('amount');
        $outstanding      = round($invoice->amount_total - $alreadyAllocated, 2);

        if ($outstanding <= 0) return 0.0;

        $creditNotes = CreditNote::where('debtor_no', $invoice->debtor_no)
            ->where('status', 'placed')
            ->where('unallocated_amount', '>', 0)
            ->orderBy('cn_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $totalApplied = 0.0;

        foreach ($creditNotes as $cn) {
            if ($outstanding <= 0) break;

            $use = min(round((float) $cn->unallocated_amount, 2), $outstanding);
            if ($use <= 0) continue;

            DebtorAllocation::create([
                'debtor_no'      => $invoice->debtor_no,
                'source_type'    => 'credit_note',
                'source_id'      => $cn->id,
                'source_no'      => $cn->cn_no,
                'inv_id'         => $invoice->id,
                'inv_no'         => $invoice->inv_no,
                'amount'         => $use,
                'allocated_date' => $tranDate,
                'created_by'     => $createdBy,
            ]);

            $cn->unallocated_amount = max(0, round((float) $cn->unallocated_amount - $use, 2));
            $cn->allocated_amount   = round((float) $cn->allocated_amount + $use, 2);
            $cn->save();

            $outstanding  -= $use;
            $totalApplied += $use;
        }

        return round($totalApplied, 2);
    }
}
