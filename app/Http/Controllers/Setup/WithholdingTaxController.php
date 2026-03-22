<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\WithholdingTax;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithholdingTaxController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = WithholdingTax::orderBy('description');
        if (!$request->boolean('inactive')) {
            $query->where('inactive', false);
        }
        return ApiResponse::success($query->get(), 'Withholding taxes retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gl_account'  => 'nullable|string|max:20',
            'description' => 'required|string|max:100',
            'tax_rate'    => 'required|numeric|min:0|max:100',
        ]);
        $wt = WithholdingTax::create($data);
        return ApiResponse::created($wt, 'Withholding tax created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $wt = WithholdingTax::findOrFail($id);
        $data = $request->validate([
            'gl_account'  => 'nullable|string|max:20',
            'description' => 'sometimes|string|max:100',
            'tax_rate'    => 'sometimes|numeric|min:0|max:100',
            'inactive'    => 'sometimes|boolean',
        ]);
        $wt->fill($data)->save();
        return ApiResponse::updated($wt->fresh(), 'Withholding tax updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $wt = WithholdingTax::findOrFail($id);
        $wt->update(['inactive' => true]);
        return ApiResponse::deleted('Withholding tax deactivated');
    }
}
