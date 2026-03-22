<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\TransactionReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionRefController extends Controller
{
    /**
     * GET /api/setup/transaction-refs?inactive=1
     */
    public function index(Request $request): JsonResponse
    {
        $query = TransactionReference::orderBy('sort_order')->orderBy('trans_name');

        if (!$request->boolean('inactive')) {
            $query->where('inactive', false);
        }

        return ApiResponse::success($query->get(), 'Transaction references retrieved');
    }

    /**
     * POST /api/setup/transaction-refs
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trans_type' => 'required|string|max:60|unique:transaction_references,trans_type',
            'trans_name' => 'required|string|max:100',
            'prefix'     => 'nullable|string|max:20',
            'pattern'    => 'required|string|max:100',
            'is_default' => 'sometimes|boolean',
            'memo'       => 'nullable|string|max:255',
        ]);

        $validated['prefix']     = $validated['prefix'] ?? '';
        $validated['memo']       = $validated['memo'] ?? '';
        $validated['sort_order'] = TransactionReference::max('sort_order') + 1;

        $ref = TransactionReference::create($validated);

        return ApiResponse::created($ref, 'Transaction reference created');
    }

    /**
     * PUT /api/setup/transaction-refs/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $ref = TransactionReference::findOrFail($id);

        $validated = $request->validate([
            'trans_name' => 'sometimes|string|max:100',
            'prefix'     => 'sometimes|nullable|string|max:20',
            'pattern'    => 'sometimes|string|max:100',
            'is_default' => 'sometimes|boolean',
            'memo'       => 'sometimes|nullable|string|max:255',
            'inactive'   => 'sometimes|boolean',
        ]);

        // Nullable → empty string for non-null DB columns
        foreach (['prefix', 'memo'] as $col) {
            if (array_key_exists($col, $validated) && is_null($validated[$col])) {
                $validated[$col] = '';
            }
        }

        $ref->fill($validated)->save();

        return ApiResponse::updated($ref->fresh(), 'Transaction reference updated');
    }

    /**
     * DELETE /api/setup/transaction-refs/{id}
     * Soft-delete: sets inactive = true.
     */
    public function destroy(int $id): JsonResponse
    {
        $ref = TransactionReference::findOrFail($id);
        $ref->update(['inactive' => true]);

        return ApiResponse::deleted('Transaction reference deactivated');
    }
}
