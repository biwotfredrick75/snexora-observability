<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CreditStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditStatusController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(CreditStatus::orderBy('description')->get(), 'Credit statuses retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'description'       => 'required|string|max:150',
            'disallow_invoices' => 'boolean',
        ]);
        return ApiResponse::created(CreditStatus::create($data), 'Credit status added');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $status = CreditStatus::findOrFail($id);
        $data   = $request->validate([
            'description'       => 'required|string|max:150',
            'disallow_invoices' => 'boolean',
        ]);
        $status->update($data);
        return ApiResponse::updated($status, 'Credit status updated');
    }

    public function destroy(int $id): JsonResponse
    {
        CreditStatus::findOrFail($id)->delete();
        return ApiResponse::deleted('Credit status deleted');
    }
}
