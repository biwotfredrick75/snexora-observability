<?php

namespace App\Http\Controllers\Internal;

use App\Events\DashboardEvent;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives the approval result POST from the Go service after it finishes
 * processing the Kafka batch. Verifies HMAC signature, then broadcasts
 * the outcome via Reverb so the frontend can react.
 */
class BulkApprovalCallbackController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->verifySignature($request);

        $approved = (int)  $request->input('approved', 0);
        $failed   = (int)  $request->input('failed',   0);
        $errors   = (array)$request->input('errors',   []);
        $message  = (string)$request->input('message', "{$approved} approved");

        broadcast(new DashboardEvent('milk_purchase', 'bulk_approved', [
            'approved' => $approved,
            'failed'   => $failed,
            'errors'   => $errors,
            'message'  => $message,
        ]));

        return ApiResponse::success(null, 'Broadcast sent');
    }

    private function verifySignature(Request $request): void
    {
        $secret    = config('kafka.callback_secret', 'change-me-in-production');
        $signature = $request->header('X-Approval-Signature', '');
        $expected  = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            abort(403, 'Invalid callback signature');
        }
    }
}
