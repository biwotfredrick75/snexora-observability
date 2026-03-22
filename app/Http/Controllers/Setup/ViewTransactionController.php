<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ViewTransactionController extends Controller
{
    /**
     * GET /api/setup/view-transactions/search
     */
    public function search(Request $request): JsonResponse
    {
        $type = $request->input('type', 'sales_invoice');
        $from = (int) $request->input('from', 1);
        $to   = (int) $request->input('to', 999999);

        // Mock data — real implementation queries transaction tables by type
        $results = [];

        return ApiResponse::success([
            'type'    => $type,
            'from'    => $from,
            'to'      => $to,
            'results' => $results,
        ], 'Search completed');
    }
}
