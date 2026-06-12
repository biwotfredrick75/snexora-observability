<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Hrm\Http\Requests\DepartmentRequest;
use App\Modules\Hrm\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Department::withCount('employees')->orderBy('name');

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($w) use ($term) {
                $w->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%");
            });
        }

        return ApiResponse::success($query->get());
    }

    public function store(DepartmentRequest $request): JsonResponse
    {
        return ApiResponse::created(
            Department::create($request->validated()),
            'Department created'
        );
    }

    public function update(DepartmentRequest $request, int $id): JsonResponse
    {
        $department = Department::find($id);

        if (! $department) {
            return ApiResponse::notFound('Department not found');
        }

        $department->update($request->validated());

        return ApiResponse::updated($department, 'Department updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $department = Department::find($id);

        if (! $department) {
            return ApiResponse::notFound('Department not found');
        }

        $department->update(['inactive' => true]);

        return ApiResponse::deleted('Department deactivated');
    }
}
