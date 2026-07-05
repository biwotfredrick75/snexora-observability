<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Driver;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Eager-loads `route` (Transport module's Fleet Registry needs plate/type/route/driver/status)
        $query = Vehicle::with(['drivers:id,name,vehicle_id', 'route:id,route_code,route_name'])->orderBy('plate_no');

        if (! $request->boolean('inactive')) {
            $query->where('inactive', false);
        }

        return ApiResponse::success($query->get(), 'Vehicles retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plate_no' => 'required|string|max:20|unique:vehicles,plate_no',
            'make'     => 'required|string|max:60',
            'model'    => 'required|string|max:60',
            'year'     => 'nullable|integer|min:1900|max:2100',
            'type'     => 'required|in:truck,van,pickup,car,motorcycle,other',
            'color'    => 'nullable|string|max:40',
            'capacity' => 'nullable|integer|min:0',
            'route_id' => 'nullable|integer|exists:transport_routes,id',
        ]);

        $vehicle = Vehicle::create($validated);

        return ApiResponse::created($vehicle->load(['drivers', 'route']), 'Vehicle created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($id);

        $validated = $request->validate([
            'plate_no' => "sometimes|string|max:20|unique:vehicles,plate_no,{$id}",
            'make'     => 'sometimes|string|max:60',
            'model'    => 'sometimes|string|max:60',
            'year'     => 'nullable|integer|min:1900|max:2100',
            'type'     => 'sometimes|in:truck,van,pickup,car,motorcycle,other',
            'color'    => 'nullable|string|max:40',
            'capacity' => 'nullable|integer|min:0',
            'inactive' => 'sometimes|boolean',
            'route_id' => 'nullable|integer|exists:transport_routes,id',
        ]);

        $vehicle->fill($validated)->save();

        return ApiResponse::updated($vehicle->fresh()->load(['drivers', 'route']), 'Vehicle updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $vehicle = Vehicle::findOrFail($id);

        // Unassign any drivers currently linked to this vehicle
        Driver::where('vehicle_id', $id)->update(['vehicle_id' => null]);

        $vehicle->update(['inactive' => true]);

        return ApiResponse::deleted('Vehicle deactivated');
    }
}
