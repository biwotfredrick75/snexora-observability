<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\TransactionAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttachDocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TransactionAttachment::orderBy('uploaded_at', 'desc');

        if ($request->filled('type')) {
            $query->where('transaction_type', $request->input('type'));
        }

        if ($request->filled('number')) {
            $query->where('transaction_number', $request->input('number'));
        }

        return ApiResponse::success($query->get(), 'Attachments retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'transaction_type'   => 'required|string|max:50',
            'transaction_number' => 'required|string|max:50',
            'description'        => 'nullable|string|max:200',
            'file'               => 'required|file|max:20480',
        ]);

        $file     = $request->file('file');
        $filename = $file->getClientOriginalName();
        $filetype = $file->getClientMimeType();
        $path     = $file->store('public/attachments');

        $attachment = TransactionAttachment::create([
            'transaction_type'   => $validated['transaction_type'],
            'transaction_number' => $validated['transaction_number'],
            'description'        => $validated['description'] ?? null,
            'filename'           => $filename,
            'filetype'           => $filetype,
            'file_path'          => $path,
            'uploaded_at'        => now(),
        ]);

        return ApiResponse::created($attachment, 'Document attached');
    }

    public function destroy(int $id): JsonResponse
    {
        $attachment = TransactionAttachment::findOrFail($id);

        Storage::delete($attachment->file_path);
        $attachment->delete();

        return ApiResponse::deleted('Attachment deleted');
    }
}
