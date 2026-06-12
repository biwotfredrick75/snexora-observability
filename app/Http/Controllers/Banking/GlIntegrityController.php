<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\GlIntegrityService;
use Illuminate\Http\JsonResponse;

class GlIntegrityController extends Controller
{
    public function check(): JsonResponse
    {
        return ApiResponse::success(GlIntegrityService::check());
    }
}
