<?php

namespace App\Http\Controllers\CasualWorkers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CasualEarningType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CasualEarningTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            CasualEarningType::with('glAccount')->orderBy('sort_order')->orderBy('name')->get(),
            'Earning types retrieved'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'           => 'required|string|max:100',
            'category'       => 'required|in:earning,deduction',
            'frequency'      => 'required|in:daily,fixed',
            'default_amount' => 'required|numeric|min:0',
            'welfare_only'   => 'boolean',
            'active'         => 'boolean',
            'sort_order'     => 'integer',
            'gl_account_id'  => 'nullable|integer|exists:gl_accounts,id',
        ]);

        return ApiResponse::created(
            CasualEarningType::create($data)->load('glAccount'),
            'Earning type created'
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $type = CasualEarningType::findOrFail($id);
        $data = $request->validate([
            'name'           => 'sometimes|string|max:100',
            'category'       => 'sometimes|in:earning,deduction',
            'frequency'      => 'sometimes|in:daily,fixed',
            'default_amount' => 'sometimes|numeric|min:0',
            'welfare_only'   => 'sometimes|boolean',
            'active'         => 'sometimes|boolean',
            'sort_order'     => 'sometimes|integer',
            'gl_account_id'  => 'sometimes|nullable|integer|exists:gl_accounts,id',
        ]);

        $type->update($data);
        return ApiResponse::updated($type->load('glAccount'), 'Earning type updated');
    }

    public function destroy(int $id): JsonResponse
    {
        CasualEarningType::findOrFail($id)->delete();
        return ApiResponse::deleted('Earning type deleted');
    }
}
