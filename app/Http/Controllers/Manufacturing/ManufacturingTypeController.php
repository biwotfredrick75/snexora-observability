<?php

namespace App\Http\Controllers\Manufacturing;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ManufacturingType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManufacturingTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $types = ManufacturingType::orderBy('sort_order')->orderBy('name')->get();
        return ApiResponse::success($types);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'           => 'required|string|max:30|unique:manufacturing_types,code',
            'name'           => 'required|string|max:100',
            'description'    => 'nullable|string',
            'icon'           => 'nullable|string|max:10',
            'color'          => 'nullable|string|max:20',
            'process_stages' => 'nullable|array',
            'qa_fields'      => 'nullable|array',
            'output_fields'  => 'nullable|array',
            'is_active'      => 'boolean',
            'sort_order'     => 'nullable|integer',
        ]);

        $validated['is_builtin'] = false;
        $type = ManufacturingType::create($validated);
        return ApiResponse::created($type, 'Manufacturing type created');
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success(ManufacturingType::findOrFail($id));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $type = ManufacturingType::findOrFail($id);

        $validated = $request->validate([
            'code'           => "sometimes|string|max:30|unique:manufacturing_types,code,{$id}",
            'name'           => 'sometimes|string|max:100',
            'description'    => 'nullable|string',
            'icon'           => 'nullable|string|max:10',
            'color'          => 'nullable|string|max:20',
            'process_stages' => 'nullable|array',
            'qa_fields'      => 'nullable|array',
            'output_fields'  => 'nullable|array',
            'is_active'      => 'boolean',
            'sort_order'     => 'nullable|integer',
        ]);

        // Prevent renaming built-in codes
        if ($type->is_builtin && isset($validated['code'])) {
            unset($validated['code']);
        }

        $type->update($validated);
        return ApiResponse::updated($type->fresh(), 'Manufacturing type updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $type = ManufacturingType::findOrFail($id);
        if ($type->is_builtin) {
            return ApiResponse::validationError(['type' => ['Built-in types cannot be deleted.']]);
        }
        $type->delete();
        return ApiResponse::deleted('Manufacturing type deleted');
    }
}
