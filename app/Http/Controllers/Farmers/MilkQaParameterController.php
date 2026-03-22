<?php

namespace App\Http\Controllers\Farmers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\MilkQaParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MilkQaParameterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MilkQaParameter::orderBy('display_order')->orderBy('parameter_name');
        if (!$request->boolean('show_inactive')) {
            $query->where('active', true);
        }
        return ApiResponse::success($query->get(), 'QA parameters retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'parameter_name' => 'required|string|max:150',
            'data_type'      => 'required|in:number,text,test',
            'display_order'  => 'sometimes|integer|min:0',
            'active'         => 'sometimes|boolean',
        ]);
        $param = MilkQaParameter::create($data);
        return ApiResponse::created($param, 'QA parameter created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $param = MilkQaParameter::findOrFail($id);
        $data  = $request->validate([
            'parameter_name' => 'sometimes|string|max:150',
            'data_type'      => 'sometimes|in:number,text,test',
            'display_order'  => 'sometimes|integer|min:0',
            'active'         => 'sometimes|boolean',
        ]);
        $param->fill($data)->save();
        return ApiResponse::updated($param->fresh(), 'QA parameter updated');
    }

    public function destroy(int $id): JsonResponse
    {
        MilkQaParameter::findOrFail($id)->delete();
        return ApiResponse::deleted('QA parameter deleted');
    }
}
