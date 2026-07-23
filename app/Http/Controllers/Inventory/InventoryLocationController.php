<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\InventoryLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryLocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = InventoryLocation::orderBy('code');
        if (!$request->boolean('inactive')) {
            $query->where('inactive', false);
        }
        // Filter by one or more types: ?types[]=grader&types[]=vendor  OR  ?type=grader
        if ($request->filled('types')) {
            $query->whereIn('type', (array) $request->input('types'));
        } elseif ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        return ApiResponse::success($query->get(), 'Inventory locations retrieved');
    }

     public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'          => 'required|string|max:20|unique:inventory_locations,code',
            'name'          => 'required|string|max:100',
            'type'          => 'nullable|string|max:50',
            'contact'       => 'nullable|string|max:100',
            'phone'         => 'nullable|string|max:30',
            'phone2'        => 'nullable|string|max:30',
            'fax'           => 'nullable|string|max:30',
            'email'         => 'nullable|email|max:100',
            'till_no'       => 'nullable|string|max:50',
            'default_price_list'    => 'nullable|string|max:100',
            'drive_name'    => 'nullable|string|max:100',
            'returns_store' => 'boolean',
            'loading_order' => 'boolean',
        ]);
                 if (array_key_exists('default_price_list', $validated)) {
    $validated['price_list'] = $validated['default_price_list'];
    unset($validated['default_price_list']);
}
        $location = InventoryLocation::create($validated);
        return ApiResponse::created($location, 'Inventory location created');
    }
    public function update(Request $request, int $id): JsonResponse
    {
        $location = InventoryLocation::findOrFail($id);
        $validated = $request->validate([
            'code'          => 'sometimes|string|max:20|unique:inventory_locations,code,' . $id,
            'name'          => 'sometimes|string|max:100',
            'type'          => 'nullable|string|max:50',
            'contact'       => 'nullable|string|max:100',
            'phone'         => 'nullable|string|max:30',
            'phone2'        => 'nullable|string|max:30',
            'fax'           => 'nullable|string|max:30',
            'email'         => 'nullable|email|max:100',
            'till_no'       => 'nullable|string|max:50',
            'default_price_list'    => 'nullable|string|max:100',
            'drive_name'    => 'nullable|string|max:100',
            'returns_store' => 'sometimes|boolean',
            'loading_order' => 'sometimes|boolean',
            'inactive'      => 'sometimes|boolean',
        ]);
    if (array_key_exists('default_price_list', $validated)) {
        $validated['price_list'] = $validated['default_price_list'];
        unset($validated['default_price_list']);
    }
        $location->fill($validated)->save();

        return ApiResponse::updated($location->fresh(), 'Inventory location updated');
    }


    public function destroy(int $id): JsonResponse
    {
        $location = InventoryLocation::findOrFail($id);
        $location->update(['inactive' => true]);
        return ApiResponse::deleted('Inventory location deactivated');
    }
}
