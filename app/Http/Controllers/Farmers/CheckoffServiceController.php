<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CheckoffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoffServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CheckoffService::orderBy('service_name');
        if (!$request->boolean('show_inactive')) {
            $query->where('active', true);
        }
        return ApiResponse::success($query->get(), 'Checkoff services retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gl_account'   => 'nullable|string|max:50',
            'service_type' => 'required|in:Deduction,Claim',
            'service_name' => 'required|string|max:150',
            'active'       => 'sometimes|boolean',
        ]);
        $service = CheckoffService::create($data);
        return ApiResponse::created($service, 'Checkoff service created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $service = CheckoffService::findOrFail($id);
        $data    = $request->validate([
            'gl_account'   => 'nullable|string|max:50',
            'service_type' => 'sometimes|in:Deduction,Claim',
            'service_name' => 'sometimes|string|max:150',
            'active'       => 'sometimes|boolean',
        ]);
        $service->fill($data)->save();
        return ApiResponse::updated($service->fresh(), 'Checkoff service updated');
    }

    public function destroy(int $id): JsonResponse
    {
        CheckoffService::findOrFail($id)->delete();
        return ApiResponse::deleted('Checkoff service deleted');
    }
}
