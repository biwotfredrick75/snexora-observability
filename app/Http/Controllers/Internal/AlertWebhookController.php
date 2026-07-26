<?php

namespace App\Http\Controllers\Internal;

use App\Events\DashboardEvent;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives Alertmanager-format webhook POSTs from SigNoz's "nexora-erp"
 * notification channel. Persists each alert to the activity log and
 * broadcasts it on the 'dashboard' Reverb channel so it can be surfaced
 * live in the UI. Auth is a shared bearer token (SigNoz's webhook channel
 * only supports bearer/basic auth, not HMAC) — set as the channel's
 * "Password" field in SigNoz and ALERTS_WEBHOOK_TOKEN here.
 */
class AlertWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->verifyToken($request);

        $payload = $request->all();
        $alerts  = $payload['alerts'] ?? [];

        foreach ($alerts as $alert) {
            $status  = $alert['status'] ?? 'unknown';
            $labels  = $alert['labels'] ?? [];
            $service = $labels['service.name'] ?? $labels['service'] ?? 'unknown';
            $name    = $labels['alertname'] ?? $payload['groupLabels']['alertname'] ?? 'Alert';

            Log::channel('single')->warning("SigNoz alert [{$status}] {$name} — service={$service}", $alert);

            ActivityLog::record(
                'signoz_alert',
                "[{$status}] {$name} on {$service}",
            );
        }

        broadcast(new DashboardEvent('signoz', 'alert', [
            'status' => $payload['status'] ?? 'unknown',
            'alerts' => $alerts,
        ]));

        return ApiResponse::success(null, 'Alert received');
    }

    private function verifyToken(Request $request): void
    {
        $expected = config('alerts.webhook_token');
        if (! $expected) {
            return; // no token configured — accept everything (dev default)
        }

        $token = $request->bearerToken() ?? $request->header('Authorization');
        if (! hash_equals($expected, (string) $token)) {
            abort(403, 'Invalid alert webhook token');
        }
    }
}
