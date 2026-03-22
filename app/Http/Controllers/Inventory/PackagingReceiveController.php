<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PackagingReceive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PackagingReceiveController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PackagingReceive::with('items.packagingType')
            ->orderBy('receiving_date', 'desc')
            ->orderBy('id', 'desc');

        if ($request->filled('from_location_id')) {
            $query->where('from_location_id', $request->from_location_id);
        }

        return ApiResponse::success($query->get(), 'Packaging receives retrieved');
    }

    public function show(int $id): JsonResponse
    {
        $receive = PackagingReceive::with('items.packagingType')->findOrFail($id);
        return ApiResponse::success($receive, 'Packaging receive retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'receiving_date'        => 'required|date',
            'from_location_id'      => 'required|string|max:30',
            'return_to_location_id' => 'nullable|string|max:30',
            'comments'              => 'nullable|string',
            'items'                 => 'nullable|array',
            'items.*.packaging_type_id' => 'required|integer|exists:packaging_types,id',
            'items.*.quantity'          => 'required|numeric|min:0',
            'items.*.condition'         => 'nullable|string|in:good,damaged',
        ]);

        DB::transaction(function () use ($validated, &$receive) {
            $receive = PackagingReceive::create(array_merge(
                $validated,
                ['created_by' => Auth::user()?->user_id]
            ));
            foreach ($validated['items'] ?? [] as $line) {
                $receive->items()->create($line);
            }
        });

        return ApiResponse::created($receive->load('items.packagingType'), 'Packaging receive created');
    }

    public function destroy(int $id): JsonResponse
    {
        $receive = PackagingReceive::findOrFail($id);
        $receive->delete();
        return ApiResponse::deleted('Packaging receive deleted');
    }
}
