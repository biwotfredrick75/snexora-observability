<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CustomerPayment;
use App\Models\DebtorAllocation;
use App\Models\MpesaTransaction;
use App\Models\SalesInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MpesaController extends Controller
{
    // ── List with filters ─────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = MpesaTransaction::with('customer:debtor_no,name')
            ->orderByDesc('trans_date')
            ->orderByDesc('Auto');

        // Filter by status (pending = no transfer_user, transferred = has transfer_user)
        if ($status = $request->get('status')) {
            if ($status === 'pending') {
                $query->where(fn ($q) => $q->where('transfer_user', '')->orWhereNull('transfer_user'));
            } elseif ($status === 'transferred') {
                $query->where('transfer_user', '!=', '')->whereNotNull('transfer_user');
            }
        }

        if ($v = $request->get('till_no'))    $query->where('BusinessShortCode', $v);
        if ($v = $request->get('date_from'))  $query->whereDate('trans_date', '>=', $v);
        if ($v = $request->get('date_to'))    $query->whereDate('trans_date', '<=', $v);
        if ($v = $request->get('debtor_no'))  $query->where('debtor_no', $v);

        $rows = $query->paginate(min((int) $request->get('per_page', 100), 500));

        // Append derived fields to each item
        $rows->getCollection()->transform(function ($tx) {
            $tx->status           = $tx->status;
            $tx->name             = $tx->name;
            $tx->transaction_date = $tx->transaction_date;
            return $tx;
        });

        return ApiResponse::paginated($rows, 'M-Pesa transactions retrieved');
    }

    // ── Check transaction status by TransID (+ optional till) ────────────────

    public function checkStatus(Request $request): JsonResponse
    {
        $transId = trim($request->get('transaction_id', ''));
        $till    = trim($request->get('till', ''));

        if (! $transId) {
            return ApiResponse::error('Transaction ID is required', 422);
        }

        $query = MpesaTransaction::with('customer:debtor_no,name', 'payment')
            ->where('TransID', $transId);
 
        if ($till) {
            $query->where('BusinessShortCode', $till);
        }

        $tx = $query->first();

        if (! $tx) {
            return ApiResponse::notFound('Transaction not found. Check the Transaction ID and Till.');
        }

        return ApiResponse::success([
            'Auto'             => $tx->Auto,
            'TransID'          => $tx->TransID,
            'TransactionType'  => $tx->TransactionType,
            'TransAmount'      => $tx->TransAmount,
            'BusinessShortCode'=> $tx->BusinessShortCode,
            'BilRef'           => $tx->BilRef,
            'MSISDN'           => $tx->MSISDN,
            'name'             => $tx->name,
            'transaction_date' => $tx->transaction_date,
            'status'           => $tx->status,
            'debtor_no'        => $tx->debtor_no,
            'customer'         => $tx->customer,
            'payment'          => $tx->payment ? [
                'payment_no'         => $tx->payment->payment_no,
                'amount'             => $tx->payment->amount,
                'allocated_amount'   => $tx->payment->allocated_amount,
                'unallocated_amount' => $tx->payment->unallocated_amount,
                'payment_date'       => $tx->payment->payment_date,
            ] : null,
            'transfer_user' => $tx->transfer_user ?: null,
            'transfer_time' => $tx->transfer_time ?? null,
        ], 'Transaction found');
    }

    // ── Distinct till (BusinessShortCode) numbers ─────────────────────────────

    public function tills(): JsonResponse
    {
        $tills = MpesaTransaction::whereNotNull('BusinessShortCode')
            ->where('BusinessShortCode', '!=', '')
            ->distinct()
            ->orderBy('BusinessShortCode')
            ->pluck('BusinessShortCode');

        return ApiResponse::success($tills, 'Till numbers retrieved');
    }

    // ── Transfer: link to customer and create CustomerPayment ─────────────────

    public function transfer(int $id, Request $request): JsonResponse
    {
        $tx = MpesaTransaction::find($id);
        if (! $tx) return ApiResponse::notFound('M-Pesa transaction not found');

        if ($tx->status === 'transferred') {
            return ApiResponse::error('Transaction already transferred', 422);
        }

        $validated = $request->validate([
            'debtor_no'         => 'required|string|max:10|exists:customers,debtor_no',
            'bank_account_code' => 'nullable|string|max:20',
            'memo'              => 'nullable|string',
        ]);

        return DB::transaction(function () use ($tx, $validated) {
            $user      = auth()->user()?->user_id ?? 'system';
            $txDate    = $tx->trans_date?->toDateString() ?? now()->toDateString();
            $amount    = (float) $tx->TransAmount;

            // Create the CustomerPayment
            $payment = CustomerPayment::create([
                'payment_no'         => 'TEMP-' . uniqid(),
                'debtor_no'          => $validated['debtor_no'],
                'payment_date'       => $txDate,
                'bank_account_code'  => $validated['bank_account_code'] ?? null,
                'payment_type'       => 'mpesa',
                'amount'             => $amount,
                'discount_amount'    => 0,
                'bank_charge'        => 0,
                'allocated_amount'   => 0,
                'unallocated_amount' => $amount,
                'reference'          => $tx->TransID,
                'memo'               => $validated['memo']
                    ?? trim("M-Pesa from {$tx->name} ({$tx->MSISDN})"),
                'status'             => 'posted',
                'created_by'         => $user,
            ]);

            $payment->update(['payment_no' => CustomerPayment::nextPaymentNo()]);

            // GL entries
            $glSetting      = DB::table('gl_settings')->first();
            $companyPref    = DB::table('company_preferences')->first();
            $debtorsAccount = ($companyPref->debtors_gl_code ?? null)
                ?: ($glSetting->receivable_account ?? 'DEBTORS');
            $mpesaAccount   = $glSetting?->pos_mpesa_account
                ?? $validated['bank_account_code']
                ?? 'MPESA';

            $this->gl($payment->id, $txDate, $mpesaAccount, $amount,
                "M-Pesa — {$tx->TransID}", $payment->payment_no, $user);
            $this->gl($payment->id, $txDate, $debtorsAccount, -$amount,
                "M-Pesa Debtors — {$tx->TransID}", $payment->payment_no, $user);

            // ── Auto-allocate to outstanding invoices (oldest-first) ──────────
            $remaining = $amount;
            $allocations = [];

            if ($remaining > 0) {
                $invoices = SalesInvoice::where('debtor_no', $validated['debtor_no'])
                    ->where('status', '!=', 'cancelled')
                    ->lockForUpdate()
                    ->orderBy('invoice_date')
                    ->orderBy('id')
                    ->get();

                foreach ($invoices as $inv) {
                    if ($remaining <= 0) break;

                    $outstanding = round($inv->amount_total - $inv->allocations()->sum('amount'), 2);
                    if ($outstanding <= 0) continue;

                    $allocAmt = round(min($outstanding, $remaining), 2);

                    DebtorAllocation::create([
                        'debtor_no'      => $validated['debtor_no'],
                        'source_type'    => 'payment',
                        'source_id'      => $payment->id,
                        'source_no'      => $payment->payment_no,
                        'inv_id'         => $inv->id,
                        'inv_no'         => $inv->inv_no,
                        'amount'         => $allocAmt,
                        'allocated_date' => $txDate,
                        'created_by'     => $user,
                    ]);

                    // No status update — outstanding balance is derived from allocations sum

                    $remaining = round($remaining - $allocAmt, 2);
                    $allocations[] = ['inv_no' => $inv->inv_no, 'allocated' => $allocAmt];
                }
            }

            $totalAllocated = round($amount - $remaining, 2);
            $payment->update([
                'allocated_amount'   => $totalAllocated,
                'unallocated_amount' => $remaining,
            ]);

            // Mark transferred in mpesa_payments (transfer_time stored as nullable timestamp)
            DB::table('mpesa_payments')
                ->where('Auto', $tx->Auto)
                ->update([
                    'debtor_no'     => $validated['debtor_no'],
                    'payment_id'    => $payment->id,
                    'allocated'     => $amount,
                    'transfer_user' => $user,
                    'transfer_time' => now()->toDateTimeString(),
                ]);

            return ApiResponse::success([
                'transaction'     => $tx->fresh('customer'),
                'payment'         => $payment->fresh(),
                'auto_allocated'  => $allocations,
                'unallocated_kes' => $remaining,
            ], 'M-Pesa payment transferred' . ($allocations ? ' and applied to ' . count($allocations) . ' invoice(s)' : '') . ' successfully');
        });
    }

    private function gl(int $transNo, string $tranDate, string $accountCode, float $amount, string $narration, string $reference, string $createdBy): void
    {
        \App\Models\GldTransaction::create([
            'trans_no'     => $transNo,
            'type'         => 12,
            'tran_date'    => $tranDate,
            'account_code' => $accountCode,
            'reference'    => $reference,
            'narration'    => $narration,
            'amount'       => $amount,
            'created_by'   => $createdBy,
        ]);
    }
}
