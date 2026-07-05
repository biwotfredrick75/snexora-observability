<?php

namespace App\Http\Controllers\Hrm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index(): JsonResponse
    {
        $departments = Department::withCount('employees')->orderBy('name')->get();

        return ApiResponse::success($departments, 'Departments retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'        => 'required|string|max:30|unique:departments,code',
            'name'        => 'required|string|max:150',
            'description' => 'nullable|string|max:255',
            'inactive'    => 'sometimes|boolean',
        ]);

        $department = Department::create($data);

        return ApiResponse::created($department, 'Department created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $department = Department::findOrFail($id);

        $data = $request->validate([
            'code'        => "sometimes|string|max:30|unique:departments,code,{$id}",
            'name'        => 'sometimes|string|max:150',
            'description' => 'nullable|string|max:255',
            'inactive'    => 'sometimes|boolean',
        ]);

        $department->fill($data)->save();

        return ApiResponse::updated($department->fresh(), 'Department updated');
    }

    public function destroy(int $id): JsonResponse
    {
        $department = Department::findOrFail($id);
        $department->update(['inactive' => true]);

        return ApiResponse::deleted('Department deactivated');
    }
}
