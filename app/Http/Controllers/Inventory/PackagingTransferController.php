<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PackagingTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PackagingTransferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PackagingTransfer::with('items.packagingType')
            ->orderBy('transfer_date', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('from_location_id')) {
            $query->where('from_location_id', $request->from_location_id);
        }

        return ApiResponse::success($query->get(), 'Packaging transfers retrieved');
    }

    public function show(int $id): JsonResponse
    {
        $transfer = PackagingTransfer::with('items.packagingType')->findOrFail($id);
        return ApiResponse::success($transfer, 'Packaging transfer retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'transfer_date'    => 'required|date',
            'from_location_id' => 'required|string|max:30',
            'to_location_id'   => 'required|string|max:30',
            'comments'         => 'nullable|string',
            'items'            => 'required|array|min:1',
            'items.*.packaging_type_id' => 'required|integer|exists:packaging_types,id',
            'items.*.qty_good'          => 'required|numeric|min:0',
        ]);

        DB::transaction(function () use ($validated, &$transfer) {
            $transfer = PackagingTransfer::create(array_merge(
                $validated,
                ['created_by' => Auth::user()?->user_id, 'status' => 'draft']
            ));
            foreach ($validated['items'] as $line) {
                $transfer->items()->create($line);
            }
        });

        return ApiResponse::created($transfer->load('items.packagingType'), 'Packaging transfer created');
    }

    public function process(int $id): JsonResponse
    {
        $transfer = PackagingTransfer::findOrFail($id);
        if ($transfer->status !== 'draft') {
            return ApiResponse::error('Transfer already processed', 422);
        }
        $transfer->update(['status' => 'processed']);
        return ApiResponse::updated($transfer->fresh(), 'Packaging transfer processed');
    }

    public function destroy(int $id): JsonResponse
    {
        $transfer = PackagingTransfer::findOrFail($id);
        if ($transfer->status !== 'draft') {
            return ApiResponse::error('Only draft transfers can be deleted', 422);
        }
        $transfer->delete();
        return ApiResponse::deleted('Packaging transfer deleted');
    }
}
