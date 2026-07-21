<?php

namespace App\Services\Payments;

use App\Models\PaymentTransaction;

/**
 * Contract every payment channel (mpesa_stk, bank_transfer, …) implements.
 * PaymentService resolves one of these per channel via config('payment.gateways')
 * and never branches on channel name itself — that's what makes adding a new
 * gateway a config change instead of a PaymentService change.
 */
interface PaymentGatewayInterface
{
    /**
     * Kick off the payment with the provider. Return a normalised array that
     * PaymentService merges onto the PaymentTransaction:
     * ['provider_reference' => ?string, 'merchant_request_id' => ?string,
     *  'status' => 'pending'|'completed'|'failed', 'raw_response' => array,
     *  'instructions' => ?array]
     */
    public function initiate(PaymentTransaction $transaction): array;

    /**
     * Normalise a provider webhook/callback payload. Return:
     * ['provider_reference' => string, 'status' => 'completed'|'failed'|'cancelled',
     *  'provider_receipt' => ?string, 'result_code' => ?string, 'result_desc' => ?string,
     *  'amount' => ?float, 'raw' => array]
     */
    public function handleCallback(array $payload): array;

    /**
     * Actively re-query the provider for current status (used when a callback
     * never arrived). Same normalised shape as handleCallback().
     */
    public function checkStatus(PaymentTransaction $transaction): array;
}
