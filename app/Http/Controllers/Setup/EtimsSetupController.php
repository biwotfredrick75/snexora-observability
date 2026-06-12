<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EtimsSetupController extends Controller
{
    private string $gatewayUrl;
    private string $apiKey;
    private string $tin;
    private string $bhfId;
    private string $dvcSrlNo;
    private string $env;

    public function __construct()
    {
        $prefs = \DB::table('company_preferences')->first();

        $this->gatewayUrl = rtrim($prefs?->etims_gateway_url ?: config('etims.gateway_url', 'http://localhost:8080'), '/');
        $this->apiKey     = $prefs?->etims_api_key    ?: config('etims.api_key', '');
        $this->tin        = $prefs?->kra_pin           ?: config('etims.tin', '');
        $this->bhfId      = $prefs?->etims_bhf_id      ?: config('etims.bhf_id', '00');
        $this->dvcSrlNo   = $prefs?->etims_dvc_srl_no  ?: config('etims.dvc_srl_no', '');
        $this->env        = $prefs?->etims_env          ?: config('etims.env', 'test');
    }

    public function status(): JsonResponse
    {
        $gatewayOnline = false;
        $gatewayMsg    = 'Unreachable';

        try {
            $resp = Http::timeout(5)->get("{$this->gatewayUrl}/health");
            $gatewayOnline = $resp->successful() && ($resp->json('status') === 'ok');
            $gatewayMsg    = $gatewayOnline ? 'Connected' : 'Unexpected response';
        } catch (\Throwable $e) {
            $gatewayMsg = $e->getMessage();
        }

        return ApiResponse::success([
            'gateway' => [
                'url'    => $this->gatewayUrl,
                'online' => $gatewayOnline,
                'msg'    => $gatewayMsg,
            ],
            'device' => [
                'tin'       => $this->tin,
                'bhf_id'    => $this->bhfId,
                'dvc_srl_no'=> $this->dvcSrlNo,
                'env'       => $this->env,
            ],
        ]);
    }

    public function initialize(): JsonResponse
    {
        return $this->proxyPost('initializer/selectInitInfo', [
            'tin'      => $this->tin,
            'bhfId'    => $this->bhfId,
            'dvcSrlNo' => $this->dvcSrlNo,
            'env'      => $this->env,
        ]);
    }

    public function syncCodes(): JsonResponse
    {
        return $this->proxyPost('code/selectCodes', [
            'tin'   => $this->tin,
            'bhfId' => $this->bhfId,
        ]);
    }

    public function syncItemClasses(): JsonResponse
    {
        return $this->proxyPost('itemClass/selectItemsClass', [
            'tin'   => $this->tin,
            'bhfId' => $this->bhfId,
        ]);
    }

    public function syncBranches(): JsonResponse
    {
        return $this->proxyPost('branches/selectBranches', [
            'tin'   => $this->tin,
            'bhfId' => $this->bhfId,
        ]);
    }

    public function syncNotices(): JsonResponse
    {
        return $this->proxyPost('notices/selectNotices', [
            'tin'   => $this->tin,
            'bhfId' => $this->bhfId,
        ]);
    }

    // ── Private helper ──────────────────────────────────────────────────────

    private function proxyPost(string $endpoint, array $payload): JsonResponse
    {
        try {
            $resp = Http::withHeaders([
                'X-API-Key'    => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post("{$this->gatewayUrl}/api/v1/{$endpoint}", $payload);

            $body = $resp->json();

            if (!$resp->successful()) {
                $raw     = $body['error'] ?? $body['resultMsg'] ?? "Gateway error {$resp->status()}";
                $isHtml  = str_contains($raw, '<html') || str_contains($raw, '<!DOCTYPE') || strlen($raw) > 300;
                $msg     = $isHtml ? "KRA server error (HTTP {$resp->status()}) — device may not be registered with KRA yet" : $raw;
                Log::error("eTIMS setup [{$endpoint}] HTTP {$resp->status()}: {$raw}");
                return ApiResponse::error($msg, 502);
            }

            // Gateway may return {"error":"..."} as a 200 without a resultCd
            if (!isset($body['resultCd']) && isset($body['error'])) {
                return ApiResponse::error($body['error'], 502);
            }

            $resultCd  = $body['resultCd']  ?? null;
            $resultMsg = $body['resultMsg'] ?? 'No message';
            $success   = $resultCd === '000';

            return ApiResponse::success([
                'resultCd'  => $resultCd,
                'resultMsg' => $resultMsg,
                'success'   => $success,
                'data'      => $body['data'] ?? null,
            ], $success ? $resultMsg : "KRA responded: {$resultMsg}");
        } catch (\Throwable $e) {
            Log::error("eTIMS setup proxy [{$endpoint}]: " . $e->getMessage());
            return ApiResponse::error('Gateway unreachable: ' . $e->getMessage(), 502);
        }
    }
}
