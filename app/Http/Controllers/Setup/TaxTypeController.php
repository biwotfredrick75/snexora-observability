<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\TaxType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TaxType::orderBy('description');
        if (!$request->boolean('inactive')) {
            $query->where('inactive', false);
        }
        return ApiResponse::success($query->get(), 'Tax types retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'description'          => 'required|string|max:100',
            'default_rate'         => 'required|numeric|min:0|max:100',
            'sales_gl_account'     => 'nullable|string|max:20',
            'purchasing_gl_account'=> 'nullable|string|max:20',
        ]);
        $type = TaxType::create($data);
        return ApiResponse::created($type, 'Tax type created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $type = TaxType::findOrFail($id);
        $data = $request->validate([
            'description'          => 'sometimes|string|max:100',
            'default_rate'         => 'sometimes|numeric|min:0|max:100',
            'sales_gl_account'     => 'nullable|string|max:20',
            'purchasing_gl_account'=> 'nullable|string|max:20',
            'inactive'             => 'sometimes|boolean',
        ]);
        $type->fill($data)->save();
        return ApiResponse::updated($type->fresh(), 'Tax type updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $type = TaxType::findOrFail($id);
        $type->update(['inactive' => true]);
        return ApiResponse::deleted('Tax type deactivated');
    }
}
