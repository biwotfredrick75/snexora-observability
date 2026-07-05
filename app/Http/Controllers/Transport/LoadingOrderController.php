<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\LoadingOrder;
use App\Models\TransportRoute;
use App\Models\Vehicle;
use App\Models\Driver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LoadingOrderController extends Controller
{
    protected function withRelations()
    {
        return LoadingOrder::with(['route:id,route_code,route_name', 'vehicle:id,plate_no', 'driver:id,name']);
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->withRelations()->orderByDesc('order_date')->orderByDesc('id');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        return ApiResponse::success($query->get(), 'Loading orders retrieved');
    }

    public function formData(): JsonResponse
    {
        return ApiResponse::success([
            'routes'   => TransportRoute::where('status', 'active')->orderBy('route_name')->get(['id', 'route_code', 'route_name']),
            'vehicles' => Vehicle::where('inactive', false)->orderBy('plate_no')->get(['id', 'plate_no']),
            'drivers'  => Driver::where('inactive', false)->orderBy('name')->get(['id', 'name']),
        ], 'Form data retrieved');
    }

    public function show(int $id): JsonResponse
    {
        $order = $this->withRelations()->with('items')->findOrFail($id);

        return ApiResponse::success($order, 'Loading order retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'route_id'         => 'required|integer|exists:transport_routes,id',
            'vehicle_id'       => 'nullable|integer|exists:vehicles,id',
            'driver_id'        => 'nullable|integer|exists:drivers,id',
            'order_date'       => 'required|date',
            'notes'            => 'nullable|string|max:500',
            'items'            => 'nullable|array',
            'items.*.description' => 'required_with:items|string|max:255',
            'items.*.quantity'     => 'nullable|numeric|min:0',
            'items.*.unit'         => 'nullable|string|max:20',
        ]);

        $order = DB::transaction(function () use ($validated) {
            $order = LoadingOrder::create([
                'order_no'   => $this->generateOrderNo(),
                'route_id'   => $validated['route_id'],
                'vehicle_id' => $validated['vehicle_id'] ?? null,
                'driver_id'  => $validated['driver_id'] ?? null,
                'order_date' => $validated['order_date'],
                'status'     => 'draft',
                'notes'      => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] ?? [] as $item) {
                $order->items()->create($item);
            }

            return $order;
        });

        return ApiResponse::created($order->load(['route', 'vehicle', 'driver', 'items']), 'Loading order created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $order = LoadingOrder::findOrFail($id);

        $validated = $request->validate([
            'route_id'   => 'sometimes|integer|exists:transport_routes,id',
            'vehicle_id' => 'nullable|integer|exists:vehicles,id',
            'driver_id'  => 'nullable|integer|exists:drivers,id',
            'order_date' => 'sometimes|date',
            'status'     => 'sometimes|in:draft,dispatched,delivered,cancelled',
            'notes'      => 'nullable|string|max:500',
        ]);

        $order->fill($validated)->save();

        return ApiResponse::updated($order->fresh()->load(['route', 'vehicle', 'driver']), 'Loading order updated');
    }

    public function dispatch(int $id): JsonResponse
    {
        $order = LoadingOrder::findOrFail($id);

        if ($order->status !== 'draft') {
            return ApiResponse::error('Only draft loading orders can be dispatched', 422);
        }

        if (! $order->vehicle_id) {
            return ApiResponse::error('A vehicle must be assigned before dispatch', 422);
        }

        $order->update(['status' => 'dispatched']);

        return ApiResponse::updated($order->fresh()->load(['route', 'vehicle', 'driver']), 'Loading order dispatched');
    }

    public function confirmDelivery(int $id): JsonResponse
    {
        $order = LoadingOrder::findOrFail($id);

        if ($order->status !== 'dispatched') {
            return ApiResponse::error('Only dispatched loading orders can be confirmed delivered', 422);
        }

        $order->update(['status' => 'delivered']);

        return ApiResponse::updated($order->fresh()->load(['route', 'vehicle', 'driver']), 'Delivery confirmed');
    }

    public function destroy(int $id): JsonResponse
    {
        $order = LoadingOrder::findOrFail($id);
        $order->update(['status' => 'cancelled']);

        return ApiResponse::deleted('Loading order cancelled');
    }

    protected function generateOrderNo(): string
    {
        $prefix = 'LO-' . now()->format('Ymd') . '-';
        $count  = LoadingOrder::where('order_no', 'like', $prefix . '%')->count();

        return $prefix . str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }
}
