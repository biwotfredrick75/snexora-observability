<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AppModuleConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

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
                // null = not configured yet — app falls back to its built-in role list.
                'roles'       => $row?->roles,
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
            'is_enabled' => 'sometimes|required|boolean',
            'roles'      => 'sometimes|nullable|array',
            'roles.*'    => 'string|distinct|in:'.Role::where('guard_name', 'api')->pluck('name')->implode(','),
        ]);

        $module = AppModuleConfig::firstOrNew(['module_id' => $moduleId]);
        $module->label       = $def['label'];
        $module->description = $def['description'];
        if (array_key_exists('is_enabled', $validated)) {
            $module->is_enabled = $validated['is_enabled'];
        }
        if (array_key_exists('roles', $validated)) {
            // Empty array = explicitly "no roles" (module hidden from everyone);
            // omit the key entirely to leave the existing config untouched.
            $module->roles = $validated['roles'];
        }
        $module->save();

        return ApiResponse::success([
            'module_id'   => $module->module_id,
            'is_enabled'  => (bool) $module->is_enabled,
            'roles'       => $module->roles,
        ], 'Module updated');
    }
}
