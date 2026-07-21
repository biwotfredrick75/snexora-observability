<?php

namespace App\Services\Payments;

use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Safaricom Daraja "Lipa Na M-Pesa Online" (STK Push). We initiate a prompt
 * on the payer's phone; Safaricom posts the result back to our callback URL
 * asynchronously (see PaymentController::mpesaStkCallback), and we can also
 * actively re-query via the STK Push Query endpoint if a callback is lost.
 */
class MpesaStkGateway implements PaymentGatewayInterface
{
    public function initiate(PaymentTransaction $transaction): array
    {
        $shortcode = config('payment.mpesa.shortcode');
        $timestamp = now()->format('YmdHis');
        $password  = base64_encode($shortcode . config('payment.mpesa.passkey') . $timestamp);
        $phone     = $this->normalizePhone($transaction->phone);

        if (! $phone) {
            throw new PaymentGatewayException('A valid M-Pesa phone number is required for STK push');
        }

        $payload = [
            'BusinessShortCode' => $shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => (int) round($transaction->amount),
            'PartyA'            => $phone,
            'PartyB'            => $shortcode,
            'PhoneNumber'       => $phone,
            'CallBackURL'       => config('payment.mpesa.callback_url'),
            'AccountReference'  => $transaction->inv_no ?: $transaction->reference,
            'TransactionDesc'   => $transaction->memo ?: 'Payment ' . $transaction->reference,
        ];

        $response = Http::withToken($this->accessToken())
            ->timeout(30)
            ->post(config('payment.mpesa.base_url') . '/mpesa/stkpush/v1/processrequest', $payload)
            ->throw()
            ->json();

        if (($response['ResponseCode'] ?? null) !== '0') {
            Log::warning('STK push rejected', ['transaction' => $transaction->reference, 'response' => $response]);
            throw new PaymentGatewayException($response['ResponseDescription'] ?? 'STK push request was rejected');
        }

        return [
            'provider_reference'   => $response['CheckoutRequestID'] ?? null,
            'merchant_request_id'  => $response['MerchantRequestID'] ?? null,
            'status'               => 'pending',
            'raw_request'          => $payload,
            'raw_response'         => $response,
        ];
    }

    public function handleCallback(array $payload): array
    {
        $stk = $payload['Body']['stkCallback'] ?? [];

        $items = collect($stk['CallbackMetadata']['Item'] ?? [])
            ->mapWithKeys(fn ($item) => [$item['Name'] => $item['Value'] ?? null]);

        $resultCode = (string) ($stk['ResultCode'] ?? '1');

        return [
            'provider_reference'  => $stk['CheckoutRequestID'] ?? null,
            'merchant_request_id' => $stk['MerchantRequestID'] ?? null,
            'status'              => $resultCode === '0' ? 'completed' : 'failed',
            'provider_receipt'    => $items->get('MpesaReceiptNumber'),
            'result_code'         => $resultCode,
            'result_desc'         => $stk['ResultDesc'] ?? null,
            'amount'              => $items->get('Amount') !== null ? (float) $items->get('Amount') : null,
            'raw'                 => $payload,
        ];
    }

    public function checkStatus(PaymentTransaction $transaction): array
    {
        $shortcode = config('payment.mpesa.shortcode');
        $timestamp = now()->format('YmdHis');
        $password  = base64_encode($shortcode . config('payment.mpesa.passkey') . $timestamp);

        $response = Http::withToken($this->accessToken())
            ->timeout(30)
            ->post(config('payment.mpesa.base_url') . '/mpesa/stkpushquery/v1/query', [
                'BusinessShortCode' => $shortcode,
                'Password'          => $password,
                'Timestamp'         => $timestamp,
                'CheckoutRequestID' => $transaction->provider_reference,
            ])
            ->json();

        $resultCode = $response['ResultCode'] ?? null;

        // 1032 = cancelled by user, 1037 = timeout, 4999/500.001.1001 = still pending on Safaricom's side
        $status = match (true) {
            $resultCode === '0'   => 'completed',
            $resultCode === null  => 'pending',
            default               => 'failed',
        };

        return [
            'provider_reference'  => $transaction->provider_reference,
            'merchant_request_id' => $response['MerchantRequestID'] ?? $transaction->merchant_request_id,
            'status'              => $status,
            'provider_receipt'    => null, // not returned by the query endpoint, only by the callback
            'result_code'         => $resultCode !== null ? (string) $resultCode : null,
            'result_desc'         => $response['ResultDesc'] ?? $response['errorMessage'] ?? null,
            'amount'              => null,
            'raw'                 => $response,
        ];
    }

    private function accessToken(): string
    {
        return Cache::remember('mpesa_access_token', 3500, function () {
            $response = Http::withBasicAuth(
                config('payment.mpesa.consumer_key'),
                config('payment.mpesa.consumer_secret'),
            )
                ->timeout(30)
                ->get(config('payment.mpesa.base_url') . '/oauth/v1/generate', ['grant_type' => 'client_credentials'])
                ->throw()
                ->json();

            return $response['access_token'];
        });
    }

    private function normalizePhone(?string $phone): ?string
    {
        if (! $phone) return null;

        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '254') && strlen($digits) === 12) return $digits;
        if (str_starts_with($digits, '0') && strlen($digits) === 10)   return '254' . substr($digits, 1);
        if (strlen($digits) === 9)                                    return '254' . $digits;

        return null;
    }
}
