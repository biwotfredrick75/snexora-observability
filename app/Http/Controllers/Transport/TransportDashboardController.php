<?php

namespace App\Http\Controllers\Transport;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\FuelEntry;
use App\Models\LoadingOrder;
use App\Models\MaintenanceRecord;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;

class TransportDashboardController extends Controller
{
    public function kpis(): JsonResponse
    {
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $fleetSize     = Vehicle::where('inactive', false)->count();
        $onRouteToday  = LoadingOrder::whereDate('order_date', $today)->where('status', 'dispatched')->count();
        $deliveredToday = LoadingOrder::whereDate('order_date', $today)->where('status', 'delivered')->count();
        $fuelCostMtd   = (float) FuelEntry::whereDate('entry_date', '>=', $monthStart)->sum('cost');
        $inMaintenance = MaintenanceRecord::whereIn('status', ['scheduled', 'in-progress'])->count();

        return ApiResponse::success([
            'fleet_size'        => $fleetSize,
            'on_route_today'    => $onRouteToday,
            'deliveries_today'  => $deliveredToday,
            'fuel_cost_mtd'     => $fuelCostMtd,
            'in_maintenance'    => $inMaintenance,
        ], 'Transport KPIs retrieved');
    }

    public function fleetRegistry(): JsonResponse
    {
        $vehicles = Vehicle::with(['route:id,route_code,route_name', 'drivers:id,name,vehicle_id'])
            ->orderBy('plate_no')
            ->get()
            ->map(function (Vehicle $v) {
                return [
                    'plate'  => $v->plate_no,
                    'type'   => $v->type,
                    'route'  => $v->route->route_name ?? 'Unassigned',
                    'driver' => optional($v->drivers->first())->name ?? 'N/A',
                    'status' => $v->status,
                ];
            });

        return ApiResponse::success($vehicles, 'Fleet registry retrieved');
    }
}
