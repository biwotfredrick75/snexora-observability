<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PrinterLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrinterLocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PrinterLocation::orderBy('name');

        if (!$request->boolean('inactive')) {
            $query->where('inactive', false);
        }

        return ApiResponse::success($query->get(), 'Printer locations retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:50',
            'description'   => 'nullable|string|max:200',
            'host'          => 'nullable|string|max:100',
            'port'          => 'nullable|integer|min:1|max:65535',
            'printer_queue' => 'nullable|string|max:100',
            'timeout'       => 'nullable|integer|min:0',
        ]);

        $validated['host'] = $validated['host'] ?? 'localhost';
        $validated['port'] = $validated['port'] ?? 515;

        $printer = PrinterLocation::create($validated);

        return ApiResponse::created($printer, 'Printer location created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $printer = PrinterLocation::findOrFail($id);

        $validated = $request->validate([
            'name'          => 'sometimes|string|max:50',
            'description'   => 'nullable|string|max:200',
            'host'          => 'nullable|string|max:100',
            'port'          => 'nullable|integer|min:1|max:65535',
            'printer_queue' => 'nullable|string|max:100',
            'timeout'       => 'nullable|integer|min:0',
            'inactive'      => 'sometimes|boolean',
        ]);

        $printer->fill($validated)->save();

        return ApiResponse::updated($printer->fresh(), 'Printer location updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $printer = PrinterLocation::findOrFail($id);
        $printer->update(['inactive' => true]);

        return ApiResponse::deleted('Printer location deactivated');
    }
}
