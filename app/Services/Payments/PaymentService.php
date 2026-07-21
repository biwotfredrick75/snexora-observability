<?php

namespace App\Services\Payments;

use App\Models\CustomerPayment;
use App\Models\DebtorAllocation;
use App\Models\GldTransaction;
use App\Models\PaymentTransaction;
use App\Models\SalesInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Channel-agnostic entry point for collecting a payment. Callers never talk
 * to MpesaStkGateway/BankTransferGateway directly — they pick a `channel`
 * string and this class resolves the right gateway from
 * config('payment.gateways'). Adding a new channel is a config change plus
 * one class implementing PaymentGatewayInterface; nothing here needs to
 * branch on channel name except the GL account lookup, which is inherently
 * channel-specific.
 */
class PaymentService
{
    private const GL_TYPE_PAYMENT = 12; // matches CustomerPaymentController::TYPE_PAYMENT / legacy MpesaController

    public function initiate(array $data): PaymentTransaction
    {
        $channel = $data['channel'] ?? null;
        $gatewayClass = config("payment.gateways.{$channel}");

        if (! $gatewayClass) {
            throw new InvalidArgumentException("Unsupported payment channel: {$channel}");
        }

        $transaction = DB::transaction(function () use ($data, $channel) {
            return PaymentTransaction::create([
                'reference'          => PaymentTransaction::nextReference(),
                'channel'            => $channel,
                'status'             => 'pending',
                'debtor_no'          => $data['debtor_no'],
                'inv_no'             => $data['inv_no'] ?? null,
                'amount'             => $data['amount'],
                'phone'              => $data['phone'] ?? null,
                'bank_account_code'  => $data['bank_account_code'] ?? null,
                'memo'               => $data['memo'] ?? null,
                'initiated_by'       => $data['initiated_by'] ?? 'system',
            ]);
        });

        $gateway = app($gatewayClass);

        try {
            $result = $gateway->initiate($transaction);
        } catch (PaymentGatewayException $e) {
            $transaction->update(['status' => 'failed', 'result_desc' => $e->getMessage()]);
            throw $e;
        }

        $transaction->update([
            'provider_reference'  => $result['provider_reference'] ?? null,
            'merchant_request_id' => $result['merchant_request_id'] ?? null,
            'status'              => $result['status'] ?? 'pending',
            'raw_request'         => $result['raw_request'] ?? null,
            'raw_response'        => $result['raw_response'] ?? null,
        ]);

        return $transaction->fresh();
    }

    /** Normalises + applies a provider webhook payload. Always resolves (never throws) so callers can ack the provider regardless of outcome. */
    public function handleCallback(string $channel, array $payload): ?PaymentTransaction
    {
        $gatewayClass = config("payment.gateways.{$channel}");
        if (! $gatewayClass) {
            throw new InvalidArgumentException("Unsupported payment channel: {$channel}");
        }

        $result = app($gatewayClass)->handleCallback($payload);

        return DB::transaction(function () use ($channel, $result) {
            $transaction = PaymentTransaction::where('channel', $channel)
                ->where('provider_reference', $result['provider_reference'])
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                Log::warning('Payment callback for unknown transaction', ['channel' => $channel, 'result' => $result]);
                return null;
            }

            if ($transaction->status !== 'pending') {
                return $transaction; // already processed — idempotent no-op
            }

            $this->applyResult($transaction, $result);

            return $transaction->fresh();
        });
    }

    public function checkStatus(string $reference): PaymentTransaction
    {
        $transaction = PaymentTransaction::where('reference', $reference)->firstOrFail();

        if ($transaction->status !== 'pending') {
            return $transaction;
        }

        $gatewayClass = config("payment.gateways.{$transaction->channel}");
        $result = app($gatewayClass)->checkStatus($transaction);

        if ($result['status'] === 'pending') {
            return $transaction; // still pending on the provider side, nothing to apply
        }

        return DB::transaction(function () use ($transaction, $result) {
            $locked = PaymentTransaction::where('id', $transaction->id)->lockForUpdate()->first();

            if ($locked->status !== 'pending') {
                return $locked; // a callback beat us to it
            }

            $this->applyResult($locked, $result);

            return $locked->fresh();
        });
    }

    /** Manual reconciliation path for channels with no automated callback (currently bank_transfer only). */
    public function confirmManual(string $reference, array $data): PaymentTransaction
    {
        return DB::transaction(function () use ($reference, $data) {
            $transaction = PaymentTransaction::where('reference', $reference)->lockForUpdate()->firstOrFail();

            if ($transaction->channel !== 'bank_transfer') {
                throw new InvalidArgumentException('Manual confirmation only applies to bank_transfer payments');
            }
            if ($transaction->status !== 'pending') {
                return $transaction; // already processed
            }

            $this->applyResult($transaction, [
                'status'            => 'completed',
                'provider_receipt'  => $data['bank_reference'] ?? null,
                'result_code'       => '0',
                'result_desc'       => 'Manually confirmed by ' . ($data['confirmed_by'] ?? 'staff'),
                'amount'            => $data['amount'] ?? null,
                'raw'               => $data,
            ]);

            return $transaction->fresh();
        });
    }

    /** Applies a normalised gateway result to an already-locked, still-pending transaction. */
    private function applyResult(PaymentTransaction $transaction, array $result): void
    {
        $transaction->update([
            'status'              => $result['status'],
            'provider_receipt'    => $result['provider_receipt'] ?? $transaction->provider_receipt,
            'merchant_request_id' => $result['merchant_request_id'] ?? $transaction->merchant_request_id,
            'result_code'         => $result['result_code'] ?? null,
            'result_desc'         => $result['result_desc'] ?? null,
            'raw_response'        => $result['raw'] ?? $transaction->raw_response,
            'completed_at'        => $result['status'] === 'completed' ? now() : null,
        ]);

        if ($result['status'] === 'completed') {
            $this->completeAsPayment($transaction, $result['amount'] ?? null);
        }
    }

    /** Posts the CustomerPayment + GL entries + invoice allocation for a completed transaction. */
    private function completeAsPayment(PaymentTransaction $transaction, ?float $confirmedAmount = null): void
    {
        if ($transaction->payment_id) {
            return; // already posted — guards against double-processing
        }

        $user   = $transaction->initiated_by ?: 'system';
        $txDate = now()->toDateString();
        $amount = $confirmedAmount ?? $transaction->amount;

        $payment = CustomerPayment::create([
            'payment_no'         => 'TEMP-' . uniqid(),
            'debtor_no'          => $transaction->debtor_no,
            'payment_date'       => $txDate,
            'bank_account_code'  => $transaction->bank_account_code,
            'payment_type'       => $transaction->channel,
            'amount'             => $amount,
            'discount_amount'    => 0,
            'bank_charge'        => 0,
            'allocated_amount'   => 0,
            'unallocated_amount' => $amount,
            'reference'          => $transaction->provider_receipt ?: $transaction->reference,
            'memo'               => $transaction->memo ?: "Payment {$transaction->reference}",
            'status'             => 'posted',
            'created_by'         => $user,
        ]);
        $payment->update(['payment_no' => CustomerPayment::nextPaymentNo()]);

        $channelAccount = $this->resolveChannelGlAccount($transaction);
        $debtorsAccount = DB::table('company_preferences')->value('debtors_gl_code')
            ?: (DB::table('gl_settings')->value('receivable_account') ?: 'DEBTORS');

        $this->gl($payment->id, $txDate, $channelAccount, $amount, "{$transaction->channel} — {$transaction->reference}", $payment->payment_no, $user);
        $this->gl($payment->id, $txDate, $debtorsAccount, -$amount, "Debtors — {$transaction->reference}", $payment->payment_no, $user);

        $remaining = $amount;
        $allocations = [];

        // Allocate to the invoice named at initiation first, if any.
        if ($transaction->inv_no) {
            $invoice = SalesInvoice::where('inv_no', $transaction->inv_no)->lockForUpdate()->first();
            if ($invoice) {
                $outstanding = round($invoice->amount_total - $invoice->allocations()->sum('amount'), 2);
                if ($outstanding > 0) {
                    $allocAmt = round(min($outstanding, $remaining), 2);
                    $this->allocate($transaction->debtor_no, $payment, $invoice, $allocAmt, $txDate, $user);
                    $remaining -= $allocAmt;
                    $allocations[] = ['inv_no' => $invoice->inv_no, 'allocated' => $allocAmt];
                }
            }
        }

        // Any remainder — oldest outstanding invoice first.
        if ($remaining > 0) {
            $invoices = SalesInvoice::where('debtor_no', $transaction->debtor_no)
                ->where('status', '!=', 'cancelled')
                ->where('inv_no', '!=', $transaction->inv_no ?? '')
                ->lockForUpdate()
                ->orderBy('invoice_date')
                ->orderBy('id')
                ->get();

            foreach ($invoices as $invoice) {
                if ($remaining <= 0) break;

                $outstanding = round($invoice->amount_total - $invoice->allocations()->sum('amount'), 2);
                if ($outstanding <= 0) continue;

                $allocAmt = round(min($outstanding, $remaining), 2);
                $this->allocate($transaction->debtor_no, $payment, $invoice, $allocAmt, $txDate, $user);
                $remaining = round($remaining - $allocAmt, 2);
                $allocations[] = ['inv_no' => $invoice->inv_no, 'allocated' => $allocAmt];
            }
        }

        $payment->update([
            'allocated_amount'   => round($amount - $remaining, 2),
            'unallocated_amount' => round($remaining, 2),
        ]);

        $transaction->update(['payment_id' => $payment->id]);
    }

    private function allocate(string $debtorNo, CustomerPayment $payment, SalesInvoice $invoice, float $amount, string $date, string $user): void
    {
        DebtorAllocation::create([
            'debtor_no'      => $debtorNo,
            'source_type'    => 'payment',
            'source_id'      => $payment->id,
            'source_no'      => $payment->payment_no,
            'inv_id'         => $invoice->id,
            'inv_no'         => $invoice->inv_no,
            'amount'         => $amount,
            'allocated_date' => $date,
            'created_by'     => $user,
        ]);
    }

    private function resolveChannelGlAccount(PaymentTransaction $transaction): string
    {
        if ($transaction->channel === 'bank_transfer' && $transaction->bank_account_code) {
            return $transaction->bank_account_code;
        }

        if ($transaction->inv_no) {
            $code = SalesInvoice::where('inv_no', $transaction->inv_no)->value('payment_channel_code');
            if ($code) return $code;
        }

        return DB::table('gl_settings')->first()?->pos_mpesa_account ?: 'MPESA';
    }

    private function gl(int $transNo, string $tranDate, string $accountCode, float $amount, string $narration, string $reference, string $createdBy): void
    {
        GldTransaction::create([
            'trans_no'     => $transNo,
            'type'         => self::GL_TYPE_PAYMENT,
            'tran_date'    => $tranDate,
            'account_code' => $accountCode,
            'reference'    => $reference,
            'narration'    => $narration,
            'amount'       => $amount,
            'created_by'   => $createdBy,
        ]);
    }
}
