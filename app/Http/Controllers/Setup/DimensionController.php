<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Dimension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DimensionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Dimension::orderBy('reference');

        if (!$request->boolean('inactive')) {
            $query->where('inactive', false);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        return ApiResponse::success($query->get(), 'Dimensions retrieved');
    }

    public function nextReference(): JsonResponse
    {
        return ApiResponse::success(['reference' => Dimension::nextReference()], 'Next reference');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:150',
            'type'             => 'required|integer|in:1,2',
            'start_date'       => 'nullable|date',
            'date_required_by' => 'nullable|date',
            'tags'             => 'nullable|array',
            'tags.*'           => 'string|max:100',
            'memo'             => 'nullable|string',
        ]);

        $validated['reference'] = Dimension::nextReference();

        $dimension = Dimension::create($validated);

        return ApiResponse::created($dimension, 'Dimension created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $dimension = Dimension::findOrFail($id);

        $validated = $request->validate([
            'name'             => 'sometimes|string|max:150',
            'type'             => 'sometimes|integer|in:1,2',
            'start_date'       => 'nullable|date',
            'date_required_by' => 'nullable|date',
            'tags'             => 'nullable|array',
            'tags.*'           => 'string|max:100',
            'memo'             => 'nullable|string',
            'inactive'         => 'sometimes|boolean',
        ]);

        $dimension->fill($validated)->save();

        return ApiResponse::updated($dimension->fresh(), 'Dimension updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $dimension = Dimension::findOrFail($id);
        $dimension->update(['inactive' => true]);

        return ApiResponse::deleted('Dimension deactivated');
    }
}
