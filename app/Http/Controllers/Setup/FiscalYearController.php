<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\FiscalYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FiscalYearController extends Controller
{
    /**
     * GET /api/setup/fiscal-years
     */
    public function index(): JsonResponse
    {
        $years = FiscalYear::orderBy('begin_date', 'desc')->get();

        return ApiResponse::success($years, 'Fiscal years retrieved');
    }

    /**
     * POST /api/setup/fiscal-years
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'begin_date' => 'required|date',
            'end_date'   => 'required|date|after:begin_date',
            'is_closed'  => 'sometimes|boolean',
        ]);

        $isFirst = FiscalYear::count() === 0;

        $year = FiscalYear::create([
            'begin_date' => $validated['begin_date'],
            'end_date'   => $validated['end_date'],
            'is_closed'  => $validated['is_closed'] ?? false,
            'is_current' => $isFirst,
        ]);

        return ApiResponse::created($year, 'Fiscal year created');
    }

    /**
     * PUT /api/setup/fiscal-years/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $year = FiscalYear::findOrFail($id);

        $validated = $request->validate([
            'begin_date' => 'sometimes|date',
            'end_date'   => 'sometimes|date|after:begin_date',
            'is_closed'  => 'sometimes|boolean',
            'is_current' => 'sometimes|boolean',
        ]);

        // When setting is_current=true, first unset all others
        if (!empty($validated['is_current'])) {
            FiscalYear::where('id', '!=', $id)->update(['is_current' => false]);
        }

        $year->fill($validated)->save();

        return ApiResponse::updated($year->fresh(), 'Fiscal year updated');
    }

    /**
     * DELETE /api/setup/fiscal-years/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $year = FiscalYear::findOrFail($id);

        if ($year->is_current) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete the current fiscal year.',
            ], 422);
        }

        $year->delete();

        return ApiResponse::deleted('Fiscal year deleted');
    }
}
