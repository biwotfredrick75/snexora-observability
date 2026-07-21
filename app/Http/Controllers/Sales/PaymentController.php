<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PaymentTransaction;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Channel-agnostic payment collection: STK push today, bank transfer today,
 * any future channel by adding it to config('payment.gateways') — this
 * controller never branches on which one. See App\Services\Payments\PaymentService.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    /** POST /api/sales/payments/initiate */
    public function initiate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel'            => ['required', 'string', Rule::in(array_keys(config('payment.gateways')))],
            'debtor_no'          => 'required|string|max:10|exists:customers,debtor_no',
            'inv_no'             => 'nullable|string|max:30|exists:sales_invoices,inv_no',
            'amount'             => 'required|numeric|min:1',
            'phone'              => 'required_if:channel,mpesa_stk|nullable|string|max:15',
            'bank_account_code'  => 'required_if:channel,bank_transfer|nullable|string|max:20',
            'memo'               => 'nullable|string|max:255',
        ]);

        $validated['initiated_by'] = auth()->user()?->user_id ?? 'system';

        try {
            $transaction = $this->payments->initiate($validated);
        } catch (PaymentGatewayException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success([
            'reference'    => $transaction->reference,
            'channel'      => $transaction->channel,
            'status'       => $transaction->status,
            'instructions' => $transaction->raw_response['instructions'] ?? null,
        ], 'Payment initiated');
    }

    /** GET /api/sales/payments/{reference}/status */
    public function status(string $reference): JsonResponse
    {
        $transaction = $this->payments->checkStatus($reference);

        return ApiResponse::success($this->present($transaction), 'Payment status retrieved');
    }

    /** POST /api/sales/payments/bank-transfer/{reference}/confirm */
    public function confirmBankTransfer(string $reference, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bank_reference' => 'required|string|max:60',
            'amount'         => 'nullable|numeric|min:1',
        ]);
        $validated['confirmed_by'] = auth()->user()?->user_id ?? 'system';

        try {
            $transaction = $this->payments->confirmManual($reference, $validated);
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success($this->present($transaction), 'Bank transfer confirmed');
    }

    /**
     * POST /api/payments/mpesa/stk/callback — public, hit by Safaricom directly
     * (no Bearer token support on their side). Always ack with 200 so Safaricom
     * doesn't retry; failures are logged, not surfaced to the caller.
     */
    public function mpesaStkCallback(Request $request): JsonResponse
    {
        try {
            $this->payments->handleCallback('mpesa_stk', $request->all());
        } catch (\Throwable $e) {
            Log::error('M-Pesa STK callback processing failed', ['error' => $e->getMessage(), 'payload' => $request->all()]);
        }

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    private function present(PaymentTransaction $transaction): array
    {
        return [
            'reference'         => $transaction->reference,
            'channel'           => $transaction->channel,
            'status'            => $transaction->status,
            'amount'            => $transaction->amount,
            'provider_receipt'  => $transaction->provider_receipt,
            'result_desc'       => $transaction->result_desc,
            'payment_id'        => $transaction->payment_id,
            'completed_at'      => $transaction->completed_at,
        ];
    }
}
