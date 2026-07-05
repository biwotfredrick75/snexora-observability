<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\FuelEntry;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FuelEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = FuelEntry::with('vehicle:id,plate_no')->orderByDesc('entry_date')->orderByDesc('id');

        if ($vehicleId = $request->get('vehicle_id')) {
            $query->where('vehicle_id', $vehicleId);
        }

        return ApiResponse::success($query->get(), 'Fuel entries retrieved');
    }

    public function formData(): JsonResponse
    {
        return ApiResponse::success([
            'vehicles' => Vehicle::where('inactive', false)->orderBy('plate_no')->get(['id', 'plate_no']),
        ], 'Form data retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_id'        => 'required|integer|exists:vehicles,id',
            'entry_date'        => 'required|date',
            'litres'            => 'required|numeric|min:0',
            'cost'              => 'required|numeric|min:0',
            'odometer_reading'  => 'nullable|integer|min:0',
            'station'           => 'nullable|string|max:150',
        ]);

        $entry = FuelEntry::create($validated);

        return ApiResponse::created($entry->load('vehicle'), 'Fuel entry recorded');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $entry = FuelEntry::findOrFail($id);

        $validated = $request->validate([
            'vehicle_id'        => 'sometimes|integer|exists:vehicles,id',
            'entry_date'        => 'sometimes|date',
            'litres'            => 'sometimes|numeric|min:0',
            'cost'              => 'sometimes|numeric|min:0',
            'odometer_reading'  => 'nullable|integer|min:0',
            'station'           => 'nullable|string|max:150',
        ]);

        $entry->fill($validated)->save();

        return ApiResponse::updated($entry->fresh()->load('vehicle'), 'Fuel entry updated');
    }

    public function destroy(int $id): JsonResponse
    {
        FuelEntry::findOrFail($id)->delete();

        return ApiResponse::deleted('Fuel entry deleted');
    }
}
