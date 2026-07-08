<?php

namespace App\Http\Controllers\Hrm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EmployeeDocumentController extends Controller
{
    private const CATEGORIES = ['contract', 'id', 'certificate', 'other'];

    public function index(int $employeeId): JsonResponse
    {
        if (! Employee::where('id', $employeeId)->exists()) return ApiResponse::notFound('Employee not found');

        $docs = EmployeeDocument::where('employee_id', $employeeId)->orderByDesc('uploaded_at')->get();

        return ApiResponse::success($docs, 'Documents retrieved');
    }

    public function store(Request $request, int $employeeId): JsonResponse
    {
        if (! Employee::where('id', $employeeId)->exists()) return ApiResponse::notFound('Employee not found');

        $validated = $request->validate([
            'category'    => 'nullable|in:' . implode(',', self::CATEGORIES),
            'description' => 'nullable|string|max:200',
            'file'        => 'required|file|max:20480',
        ]);

        $file = $request->file('file');
        // Explicit 'public' disk — the default 'local' disk root is
        // storage/app/private, which the public/storage symlink does not
        // reach, so store('public/...') without a disk silently produces
        // an unreachable file.
        $path = $file->store('employee-documents', 'public');

        $doc = EmployeeDocument::create([
            'employee_id' => $employeeId,
            'category'    => $validated['category'] ?? 'other',
            'description' => $validated['description'] ?? null,
            'filename'    => $file->getClientOriginalName(),
            'filetype'    => $file->getClientMimeType(),
            'file_path'   => $path,
            'uploaded_at' => now(),
        ]);

        return ApiResponse::created($doc, 'Document uploaded');
    }

    public function destroy(int $id): JsonResponse
    {
        $doc = EmployeeDocument::find($id);
        if (! $doc) return ApiResponse::notFound('Document not found');

        Storage::disk('public')->delete($doc->file_path);
        $doc->delete();

        return ApiResponse::deleted('Document deleted');
    }
}
