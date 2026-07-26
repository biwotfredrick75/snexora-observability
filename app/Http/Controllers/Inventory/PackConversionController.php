<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Inventory\PackConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackConversionController extends Controller
{
    public function __construct(private PackConversionService $service)
    {
    }

    public function preview(Request $request, string $itemId): JsonResponse
    {
        $validated = $request->validate([
            'loc_code' => 'required|string|exists:inventory_locations,code',
        ]);
        $data = $this->service->preview($itemId, $validated['loc_code']);
        return ApiResponse::success($data, 'Pack conversion preview retrieved');
    }

    public function store(Request $request, string $itemId): JsonResponse
    {
        $validated = $request->validate([
            'loc_code'                     => 'required|string|exists:inventory_locations,code',
            'reference'                    => 'nullable|string|max:60',
            'lines'                        => 'required|array|min:1',
            'lines.*.component_id'         => 'required|string|exists:items,stock_id',
            'lines.*.qty_to_produce'       => 'required|numeric|min:0',
        ]);

        try {
            $result = $this->service->convert(
                $itemId,
                $validated['loc_code'],
                $validated['lines'],
                auth()->user()?->user_id ?? '',
                $validated['reference'] ?? null
            );
        } catch (\RuntimeException $e) {
            return ApiResponse::validationError(['lines' => $e->getMessage()]);
        }

        return ApiResponse::created($result, 'Pack conversion posted');
    }
}
