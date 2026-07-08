<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\GlAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payment channels a customer can be directed to pay via when an invoice is
 * placed — e.g. an M-Pesa Paybill, and in future a bank account for direct
 * transfer. A channel is just a gl_accounts row tagged with a
 * `payment_provider` (see ChartOfAccountsController / gl_accounts schema);
 * there is no separate payment-channels table.
 */
class PaymentChannelController extends Controller
{
    /** GET /api/sales/payment-channels?provider=mpesa */
    public function index(Request $request): JsonResponse
    {
        $channels = GlAccount::paymentChannels($request->get('provider'))
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'payment_provider', 'mpesa_shortcode']);

        return ApiResponse::success($channels, 'Payment channels retrieved');
    }
}
