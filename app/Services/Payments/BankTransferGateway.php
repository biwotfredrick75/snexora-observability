<?php

namespace App\Services\Payments;

use App\Models\PaymentTransaction;

/**
 * No live bank API is wired up yet (see project decision: build the
 * interface now, plug in a real bank API — e.g. Equity Jenga, KCB Buni —
 * later without touching PaymentService or the routes/controller). Until
 * then, "initiating" a bank transfer just hands the payer transfer
 * instructions; confirmation is manual — see PaymentService::confirmManual(),
 * called once staff sees the funds land (reconciled the same way as
 * BankTransactionController's existing manual entries).
 */
class BankTransferGateway implements PaymentGatewayInterface
{
    public function initiate(PaymentTransaction $transaction): array
    {
        $instructions = [
            'account_name'   => config('payment.bank.account_name'),
            'account_number' => config('payment.bank.account_number'),
            'bank_name'      => config('payment.bank.bank_name'),
            'branch'         => config('payment.bank.branch'),
            'swift'          => config('payment.bank.swift'),
            'reference'      => $transaction->inv_no ?: $transaction->reference,
        ];

        return [
            'provider_reference' => $transaction->reference,
            'status'             => 'pending',
            'raw_response'       => ['instructions' => $instructions],
            'instructions'       => $instructions,
        ];
    }

    public function handleCallback(array $payload): array
    {
        throw new PaymentGatewayException(
            'Bank transfers have no automated callback yet — confirm manually via PaymentService::confirmManual()'
        );
    }

    public function checkStatus(PaymentTransaction $transaction): array
    {
        return [
            'provider_reference' => $transaction->reference,
            'status'             => $transaction->status, // no provider to poll — reflects our own record
            'provider_receipt'   => $transaction->provider_receipt,
            'result_code'        => $transaction->result_code,
            'result_desc'        => $transaction->result_desc ?? 'Awaiting manual confirmation of funds received',
            'amount'             => null,
            'raw'                => [],
        ];
    }
}
