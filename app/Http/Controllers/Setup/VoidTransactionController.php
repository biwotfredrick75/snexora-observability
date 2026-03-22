<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoidTransactionController extends Controller
{
    /**
     * GET /api/setup/void-transaction/search
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

    /**
     * POST /api/setup/void-transaction/void
     */
    public function void(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'           => 'required|string',
            'transaction_id' => 'required',
            'void_date'      => 'required|date',
            'reason'         => 'nullable|string|max:500',
        ]);

        // Real implementation: find transaction by type+id, mark as voided
        return ApiResponse::success(null, 'Transaction voided successfully');
    }
}
