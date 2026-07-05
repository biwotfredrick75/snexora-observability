<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AppModuleConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppModuleController extends Controller
{
    // GET /setup/app-modules
    public function index(): JsonResponse
    {
        $saved = AppModuleConfig::all()->keyBy('module_id');

        $modules = collect(AppModuleConfig::$definitions)->map(function ($def) use ($saved) {
            $row = $saved->get($def['module_id']);
            return [
                'module_id'   => $def['module_id'],
                'label'       => $def['label'],
                'description' => $def['description'],
                'is_enabled'  => $row ? (bool) $row->is_enabled : true,
            ];
        });

        return ApiResponse::success($modules, 'App modules retrieved');
    }

    // PUT /setup/app-modules/{moduleId}
    public function update(Request $request, string $moduleId): JsonResponse
    {
        $def = collect(AppModuleConfig::$definitions)
            ->firstWhere('module_id', $moduleId);

        if (! $def) {
            return ApiResponse::notFound('Module not found');
        }

        $validated = $request->validate([
            'is_enabled' => 'required|boolean',
        ]);

        $module = AppModuleConfig::updateOrCreate(
            ['module_id'   => $moduleId],
            [
                'label'       => $def['label'],
                'description' => $def['description'],
                'is_enabled'  => $validated['is_enabled'],
            ]
        );

        return ApiResponse::success([
            'module_id'  => $module->module_id,
            'is_enabled' => (bool) $module->is_enabled,
        ], 'Module updated');
    }
}
