<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Driver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Driver::with(['vehicle:id,plate_no,make,model'])->orderBy('name');

        if (! $request->boolean('inactive')) {
            $query->where('inactive', false);
        }

        return ApiResponse::success($query->get(), 'Drivers retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:100',
            'license_no'    => 'nullable|string|max:40',
            'license_class' => 'nullable|string|max:10',
            'phone'         => 'nullable|string|max:20',
            'vehicle_id'    => 'nullable|integer|exists:vehicles,id',
        ]);

        $driver = Driver::create($validated);

        return ApiResponse::created($driver->load('vehicle:id,plate_no,make,model'), 'Driver created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $driver = Driver::findOrFail($id);

        $validated = $request->validate([
            'name'          => 'sometimes|string|max:100',
            'license_no'    => 'nullable|string|max:40',
            'license_class' => 'nullable|string|max:10',
            'phone'         => 'nullable|string|max:20',
            'vehicle_id'    => 'nullable|integer|exists:vehicles,id',
            'inactive'      => 'sometimes|boolean',
        ]);

        $driver->fill($validated)->save();

        return ApiResponse::updated($driver->fresh()->load('vehicle:id,plate_no,make,model'), 'Driver updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $driver = Driver::findOrFail($id);
        $driver->update(['inactive' => true]);

        return ApiResponse::deleted('Driver deactivated');
    }
}
