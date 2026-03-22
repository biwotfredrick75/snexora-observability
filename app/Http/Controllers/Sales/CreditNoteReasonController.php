<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CreditNoteReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreditNoteReasonController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(CreditNoteReason::orderBy('reason_code')->get(), 'Credit note reasons retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason_code' => 'required|string|max:20',
            'reason_name' => 'required|string|max:150',
            'description' => 'nullable|string|max:255',
        ]);
        return ApiResponse::created(CreditNoteReason::create($data), 'Credit note reason added');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $reason = CreditNoteReason::findOrFail($id);
        $data   = $request->validate([
            'reason_code' => 'required|string|max:20',
            'reason_name' => 'required|string|max:150',
            'description' => 'nullable|string|max:255',
        ]);
        $reason->update($data);
        return ApiResponse::updated($reason, 'Credit note reason updated');
    }

    public function destroy(int $id): JsonResponse
    {
        CreditNoteReason::findOrFail($id)->delete();
        return ApiResponse::deleted('Credit note reason deleted');
    }
}
