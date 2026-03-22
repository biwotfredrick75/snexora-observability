<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\CompanyDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyDatabaseController extends Controller
{
    public function index(): JsonResponse
    {
        $companies = CompanyDatabase::orderBy('company')->get();

        return ApiResponse::success($companies, 'Company databases retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company'      => 'required|string|max:100',
            'host'         => 'nullable|string|max:100',
            'port'         => 'nullable|string|max:10',
            'db_user'      => 'required|string|max:100',
            'db_password'  => 'nullable|string|max:255',
            'db_name'      => 'required|string|max:100',
            'collation'    => 'nullable|string|max:50',
            'table_prefix' => 'nullable|string|max:10',
            'is_default'   => 'sometimes|boolean',
        ]);

        $validated['host']      = $validated['host'] ?? 'localhost';
        $validated['collation'] = $validated['collation'] ?? 'utf8mb4_unicode_ci';
        $validated['db_password'] = $validated['db_password'] ?? 'password';

        if (!empty($validated['is_default'])) {
            CompanyDatabase::where('is_default', true)->update(['is_default' => false]);
        }

        $company = CompanyDatabase::create($validated);

        return ApiResponse::created($company, 'Company database created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $company = CompanyDatabase::findOrFail($id);

        $validated = $request->validate([
            'company'      => 'sometimes|string|max:100',
            'host'         => 'nullable|string|max:100',
            'port'         => 'nullable|string|max:10',
            'db_user'      => 'sometimes|string|max:100',
            'db_password'  => 'nullable|string|max:255',
            'db_name'      => 'sometimes|string|max:100',
            'collation'    => 'nullable|string|max:50',
            'table_prefix' => 'nullable|string|max:10',
            'is_default'   => 'sometimes|boolean',
        ]);

        if (!empty($validated['is_default'])) {
            CompanyDatabase::where('id', '!=', $id)->where('is_default', true)->update(['is_default' => false]);
        }

        $company->fill($validated)->save();

        return ApiResponse::updated($company->fresh(), 'Company database updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $company = CompanyDatabase::findOrFail($id);

        if ($company->is_default) {
            return ApiResponse::validationError(['company' => ['Cannot delete the default/current company database.']]);
        }

        $company->delete();

        return ApiResponse::deleted('Company database deleted');
    }
}
