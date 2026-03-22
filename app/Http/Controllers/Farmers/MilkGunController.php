<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\MilkGun;
use App\Models\MilkRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MilkGunController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MilkGun::orderBy('gun_no');
        if ($request->filled('route_id')) {
            $query->where('route_id', $request->route_id);
        }
        $guns   = $query->get();
        $routes = MilkRoute::orderBy('route_name')->get(['id', 'route_name', 'route_code']);
        return ApiResponse::success(['guns' => $guns, 'routes' => $routes], 'Milk guns retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'route_id' => 'nullable|integer',
            'grader'   => 'required|string|max:150',
            'gun_no'   => 'required|string|max:50',
            'capacity' => 'required|numeric|min:0',
            'status'   => 'sometimes|string|max:20',
        ]);
        $gun = MilkGun::create($data);
        return ApiResponse::created($gun, 'Milk gun created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $gun  = MilkGun::findOrFail($id);
        $data = $request->validate([
            'route_id' => 'nullable|integer',
            'grader'   => 'sometimes|string|max:150',
            'gun_no'   => 'sometimes|string|max:50',
            'capacity' => 'sometimes|numeric|min:0',
            'status'   => 'sometimes|string|max:20',
        ]);
        $gun->fill($data)->save();
        return ApiResponse::updated($gun->fresh(), 'Milk gun updated');
    }

    public function destroy(int $id): JsonResponse
    {
        MilkGun::findOrFail($id)->delete();
        return ApiResponse::deleted('Milk gun deleted');
    }
}
