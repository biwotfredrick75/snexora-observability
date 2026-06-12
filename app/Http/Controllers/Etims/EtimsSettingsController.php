<?php

namespace App\Http\Controllers\Etims;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CompanyPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EtimsSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $prefs = CompanyPreference::first();

        return ApiResponse::success([
            'kra_pin'         => $prefs?->kra_pin         ?? '',
            'etims_bhf_id'    => $prefs?->etims_bhf_id    ?? '00',
            'etims_dvc_srl_no'=> $prefs?->etims_dvc_srl_no ?? '',
            'etims_env'       => $prefs?->etims_env        ?? 'test',
            'etims_api_key'   => $prefs?->etims_api_key    ?? '',
            'etims_gateway_url'=> $prefs?->etims_gateway_url ?? 'http://localhost:8080',
            'company_name'    => $prefs?->name             ?? '',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kra_pin'          => 'required|string|max:50',
            'etims_bhf_id'     => 'required|string|max:10',
            'etims_dvc_srl_no' => 'required|string|max:100',
            'etims_env'        => 'required|in:test,prod',
            'etims_api_key'    => 'required|string|max:255',
            'etims_gateway_url'=> 'required|url|max:255',
        ]);

        $prefs = CompanyPreference::first();
        if (!$prefs) {
            return ApiResponse::error('Company preferences not found. Set up your company profile first.', 404);
        }

        $prefs->update($validated);

        return ApiResponse::updated($prefs->only(array_keys($validated)), 'eTIMS settings saved');
    }

    public function testConnection(Request $request): JsonResponse
    {
        $url = $request->input('etims_gateway_url', CompanyPreference::first()?->etims_gateway_url ?? 'http://localhost:8080');
        $url = rtrim($url, '/');

        try {
            $resp = Http::timeout(5)->get("{$url}/health");
            $ok   = $resp->successful() && ($resp->json('status') === 'ok');

            return ApiResponse::success([
                'online'  => $ok,
                'message' => $ok ? 'Gateway is reachable' : ('Unexpected response: ' . $resp->body()),
                'url'     => $url,
            ]);
        } catch (\Throwable $e) {
            Log::warning("eTIMS gateway test failed: " . $e->getMessage());
            return ApiResponse::success([
                'online'  => false,
                'message' => $e->getMessage(),
                'url'     => $url,
            ]);
        }
    }
}
