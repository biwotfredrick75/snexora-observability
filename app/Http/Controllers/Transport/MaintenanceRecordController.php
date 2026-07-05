<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\MaintenanceRecord;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaintenanceRecordController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MaintenanceRecord::with('vehicle:id,plate_no')->orderByDesc('service_date')->orderByDesc('id');

        if ($vehicleId = $request->get('vehicle_id')) {
            $query->where('vehicle_id', $vehicleId);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        return ApiResponse::success($query->get(), 'Maintenance records retrieved');
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
            'vehicle_id'         => 'required|integer|exists:vehicles,id',
            'service_date'       => 'required|date',
            'description'        => 'required|string|max:255',
            'cost'               => 'required|numeric|min:0',
            'next_service_due'   => 'nullable|date',
            'status'             => 'sometimes|in:scheduled,in-progress,completed',
        ]);

        $record = MaintenanceRecord::create($validated);

        return ApiResponse::created($record->load('vehicle'), 'Maintenance record created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $record = MaintenanceRecord::findOrFail($id);

        $validated = $request->validate([
            'vehicle_id'         => 'sometimes|integer|exists:vehicles,id',
            'service_date'       => 'sometimes|date',
            'description'        => 'sometimes|string|max:255',
            'cost'               => 'sometimes|numeric|min:0',
            'next_service_due'   => 'nullable|date',
            'status'             => 'sometimes|in:scheduled,in-progress,completed',
        ]);

        $record->fill($validated)->save();

        return ApiResponse::updated($record->fresh()->load('vehicle'), 'Maintenance record updated');
    }

    public function destroy(int $id): JsonResponse
    {
        MaintenanceRecord::findOrFail($id)->delete();

        return ApiResponse::deleted('Maintenance record deleted');
    }
}
