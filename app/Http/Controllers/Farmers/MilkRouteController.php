<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\MilkRoute;
use App\Models\MilkCollectionSession;
use App\Models\InventoryLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Farmer;

class MilkRouteController extends Controller
{
    public function index(): JsonResponse
    {
        $routes    = MilkRoute::orderBy('route_name')->get();
        $sessions  = MilkCollectionSession::where('active', true)->orderBy('description')->get(['id', 'description']);
        $locations = [];
        if (class_exists(InventoryLocation::class)) {
            $locations = InventoryLocation::where('inactive', false)->orderBy('name')->get(['id', 'name']);
        }
        return ApiResponse::success(
            ['routes' => $routes, 'sessions' => $sessions, 'locations' => $locations],
            'Routes retrieved'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'route_code'       => 'required|string|max:50',
            'route_name'       => 'required|string|max:150',
            'location_id'      => 'nullable|integer',
            'merged_route_ids' => 'nullable|array',
            'sessions'         => 'nullable|array',
            'status'           => 'sometimes|string|max:20',
        ]);
        $route = MilkRoute::create($data);
        return ApiResponse::created($route, 'Route created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $route = MilkRoute::findOrFail($id);
        $data  = $request->validate([
            'route_code'       => 'sometimes|string|max:50',
            'route_name'       => 'sometimes|string|max:150',
            'location_id'      => 'nullable|integer',
            'merged_route_ids' => 'nullable|array',
            'sessions'         => 'nullable|array',
            'status'           => 'sometimes|string|max:20',
        ]);
        $route->fill($data)->save();
        return ApiResponse::updated($route->fresh(), 'Route updated');
    }

    public function destroy(int $id): JsonResponse
    {
        MilkRoute::findOrFail($id)->delete();
        return ApiResponse::deleted('Route deleted');
    }

    public function farmers(int $id): JsonResponse
    {
        $route   = MilkRoute::findOrFail($id);
        $farmers = Farmer::where('route_id', $id)
            ->where('status', '!=', 'inactive')
            ->orderBy('full_name')
            ->get(['id', 'farmer_no', 'full_name', 'short_name', 'phone', 'status']);

        return ApiResponse::success(
            ['route' => $route, 'farmers' => $farmers],
            'Farmers for route retrieved'
        );
    }
}
