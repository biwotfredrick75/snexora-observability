<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\DebitNote;
use App\Models\DebitNoteReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DebitNoteReasonController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(DebitNoteReason::orderBy('reason_name')->get(), 'Debit note reasons retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason_code' => 'required|string|max:20|unique:debit_note_reasons,reason_code',
            'reason_name' => 'required|string|max:150',
            'description' => 'nullable|string|max:255',
            'gl_account'  => 'nullable|string|max:15',
        ]);

        $reason = DebitNoteReason::create($data);
        return ApiResponse::created($reason, 'Debit note reason created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $reason = DebitNoteReason::find($id);
        if (! $reason) return ApiResponse::notFound('Reason not found');

        $data = $request->validate([
            'reason_code' => 'sometimes|string|max:20|unique:debit_note_reasons,reason_code,' . $id,
            'reason_name' => 'sometimes|string|max:150',
            'description' => 'nullable|string|max:255',
            'gl_account'  => 'nullable|string|max:15',
        ]);

        $reason->update($data);
        return ApiResponse::updated($reason->fresh(), 'Debit note reason updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $reason = DebitNoteReason::find($id);
        if (! $reason) return ApiResponse::notFound('Reason not found');

        if (DebitNote::where('reason_id', $id)->exists()) {
            return ApiResponse::error('Reason is in use by existing debit notes and cannot be deleted', 422);
        }

        $reason->delete();
        return ApiResponse::deleted('Debit note reason deleted');
    }
}
