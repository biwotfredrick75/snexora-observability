<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\SalesType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(SalesType::orderBy('type_name')->get(), 'Sales types retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type_name' => 'required|string|max:100',
            'factor'    => 'required|numeric|min:0',
            'tax_incl'  => 'boolean',
        ]);
        return ApiResponse::created(SalesType::create($data), 'Sales type added');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $type = SalesType::findOrFail($id);
        $data = $request->validate([
            'type_name' => 'required|string|max:100',
            'factor'    => 'required|numeric|min:0',
            'tax_incl'  => 'boolean',
        ]);
        $type->update($data);
        return ApiResponse::updated($type, 'Sales type updated');
    }

    public function destroy(int $id): JsonResponse
    {
        SalesType::findOrFail($id)->delete();
        return ApiResponse::deleted('Sales type deleted');
    }
}
