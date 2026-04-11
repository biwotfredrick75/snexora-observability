<?php

namespace App\Http\Controllers\Blockchain;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\BlockchainAnchor;
use App\Services\Blockchain\BlockchainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlockchainController extends Controller
{
    public function __construct(private readonly BlockchainService $blockchain) {}

    /**
     * GET /blockchain/anchor?trans_type=&trans_no=
     * Returns the stored anchor record for a document.
     */
    public function getAnchor(Request $request): JsonResponse
    {
        $request->validate([
            'trans_type' => 'required|string',
            'trans_no'   => 'required|string',
        ]);

        $anchor = BlockchainAnchor::where('trans_type', $request->trans_type)
            ->where('trans_no', $request->trans_no)
            ->latest()
            ->first();

        if (! $anchor) {
            return ApiResponse::notFound('No blockchain anchor found for this document');
        }

        return ApiResponse::success($anchor, 'Anchor record retrieved');
    }

    /**
     * POST /blockchain/verify
     * Verifies a tx_hash + doc_hash pair against what is stored on-chain.
     * Body: { tx_hash, doc_hash }
     */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tx_hash'  => 'required|string',
            'doc_hash' => 'required|string|size:64',
        ]);

        $result = $this->blockchain->verify($validated['tx_hash'], $validated['doc_hash']);

        if ($result === null) {
            return ApiResponse::error(
                'Could not reach the blockchain service. Please try again later.',
                503
            );
        }

        return ApiResponse::success($result, $result['valid'] ?? false
            ? 'Document hash verified — record is authentic'
            : 'Verification failed — hash does not match on-chain record'
        );
    }

    /**
     * GET /blockchain/history?trans_type=&limit=
     * Returns recent anchor records, newest first.
     */
    public function history(Request $request): JsonResponse
    {
        $query = BlockchainAnchor::orderByDesc('id');

        if ($request->filled('trans_type')) {
            $query->where('trans_type', $request->trans_type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $limit   = min((int) $request->get('limit', 50), 200);
        $anchors = $query->limit($limit)->get();

        return ApiResponse::success($anchors, 'Anchor history');
    }
}
