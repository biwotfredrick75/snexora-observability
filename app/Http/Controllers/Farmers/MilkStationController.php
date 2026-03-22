<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\MilkStation;
use App\Models\MilkRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MilkStationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MilkStation::orderBy('station_name');
        if ($request->filled('route_id')) {
            $query->where('route_id', $request->route_id);
        }
        $stations = $query->get();
        $routes   = MilkRoute::orderBy('route_name')->get(['id', 'route_name', 'route_code']);
        return ApiResponse::success(['stations' => $stations, 'routes' => $routes], 'Milk stations retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'route_id'     => 'nullable|integer',
            'station_name' => 'required|string|max:150',
            'status'       => 'sometimes|string|max:20',
        ]);
        $station = MilkStation::create($data);
        return ApiResponse::created($station, 'Milk station created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $station = MilkStation::findOrFail($id);
        $data    = $request->validate([
            'route_id'     => 'nullable|integer',
            'station_name' => 'sometimes|string|max:150',
            'status'       => 'sometimes|string|max:20',
        ]);
        $station->fill($data)->save();
        return ApiResponse::updated($station->fresh(), 'Milk station updated');
    }

    public function destroy(int $id): JsonResponse
    {
        MilkStation::findOrFail($id)->delete();
        return ApiResponse::deleted('Milk station deleted');
    }
}
