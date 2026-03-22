<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PosSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosSettingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PosSetting::orderBy('name');

        if (!$request->boolean('inactive')) {
            $query->where('inactive', false);
        }

        return ApiResponse::success($query->get(), 'POS settings retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'              => 'required|string|max:100',
            'allow_credit_sale' => 'sometimes|boolean',
            'allow_cash_sale'   => 'sometimes|boolean',
            'location'          => 'nullable|string|max:100',
            'default_account'   => 'nullable|string|max:20',
        ]);

        $pos = PosSetting::create($validated);

        return ApiResponse::created($pos, 'POS setting created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $pos = PosSetting::findOrFail($id);

        $validated = $request->validate([
            'name'              => 'sometimes|string|max:100',
            'allow_credit_sale' => 'sometimes|boolean',
            'allow_cash_sale'   => 'sometimes|boolean',
            'location'          => 'nullable|string|max:100',
            'default_account'   => 'nullable|string|max:20',
            'inactive'          => 'sometimes|boolean',
        ]);

        $pos->fill($validated)->save();

        return ApiResponse::updated($pos->fresh(), 'POS setting updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $pos = PosSetting::findOrFail($id);
        $pos->update(['inactive' => true]);

        return ApiResponse::deleted('POS setting deactivated');
    }
}
