<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PrintingProfile;
use App\Models\PrintingProfileItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrintingProfileController extends Controller
{
    /**
     * GET /api/setup/printing-profiles
     * Return all profiles (without items).
     */
    public function index(): JsonResponse
    {
        $profiles = PrintingProfile::orderBy('name')->get();

        return ApiResponse::success($profiles, 'Printing profiles retrieved');
    }

    /**
     * GET /api/setup/printing-profiles/{id}
     * Return one profile with its items.
     */
    public function show(int $id): JsonResponse
    {
        $profile = PrintingProfile::with('items')->findOrFail($id);

        return ApiResponse::success($profile, 'Printing profile retrieved');
    }

    /**
     * POST /api/setup/printing-profiles
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:100',
            'items'         => 'sometimes|array',
            'items.*.report_id' => 'required|string|max:20',
            'items.*.printer'   => 'required|string|max:50',
        ]);

        $profile = PrintingProfile::create(['name' => $validated['name']]);

        if (!empty($validated['items'])) {
            $rows = array_map(fn($item) => [
                'profile_id' => $profile->id,
                'report_id'  => $item['report_id'],
                'printer'    => $item['printer'],
                'created_at' => now(),
                'updated_at' => now(),
            ], $validated['items']);

            PrintingProfileItem::insert($rows);
        }

        return ApiResponse::created($profile->load('items'), 'Printing profile created');
    }

    /**
     * PUT /api/setup/printing-profiles/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $profile = PrintingProfile::findOrFail($id);

        $validated = $request->validate([
            'name'              => 'sometimes|string|max:100',
            'items'             => 'sometimes|array',
            'items.*.report_id' => 'required|string|max:20',
            'items.*.printer'   => 'required|string|max:50',
        ]);

        if (isset($validated['name'])) {
            $profile->update(['name' => $validated['name']]);
        }

        if (isset($validated['items'])) {
            // Delete existing items and re-insert (sync pattern)
            $profile->items()->delete();

            if (!empty($validated['items'])) {
                $rows = array_map(fn($item) => [
                    'profile_id' => $profile->id,
                    'report_id'  => $item['report_id'],
                    'printer'    => $item['printer'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $validated['items']);

                PrintingProfileItem::insert($rows);
            }
        }

        return ApiResponse::updated($profile->load('items'), 'Printing profile updated');
    }

    /**
     * DELETE /api/setup/printing-profiles/{id}
     * Items cascade-delete via FK.
     */
    public function destroy(int $id): JsonResponse
    {
        $profile = PrintingProfile::findOrFail($id);
        $profile->delete();

        return ApiResponse::deleted('Printing profile deleted');
    }
}
