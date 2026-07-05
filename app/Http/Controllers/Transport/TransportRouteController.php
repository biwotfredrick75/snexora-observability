<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\TransportRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransportRouteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TransportRoute::orderBy('route_name');

        if (! $request->boolean('inactive')) {
            $query->where('status', 'active');
        }

        return ApiResponse::success($query->get(), 'Routes retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'route_code'  => 'required|string|max:30|unique:transport_routes,route_code',
            'route_name'  => 'required|string|max:150',
            'description' => 'nullable|string|max:255',
            'status'      => 'sometimes|in:active,inactive',
        ]);

        $route = TransportRoute::create($validated);

        return ApiResponse::created($route, 'Route created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $route = TransportRoute::findOrFail($id);

        $validated = $request->validate([
            'route_code'  => "sometimes|string|max:30|unique:transport_routes,route_code,{$id}",
            'route_name'  => 'sometimes|string|max:150',
            'description' => 'nullable|string|max:255',
            'status'      => 'sometimes|in:active,inactive',
        ]);

        $route->fill($validated)->save();

        return ApiResponse::updated($route->fresh(), 'Route updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $route = TransportRoute::findOrFail($id);
        $route->update(['status' => 'inactive']);

        return ApiResponse::deleted('Route deactivated');
    }
}
