<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Hrm\Http\Requests\EmployeeRequest;
use App\Modules\Hrm\Models\Department;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\JobTitle;
use App\Modules\Hrm\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function __construct(private EmployeeService $service) {}

    /**
     * Dropdown data for the employee form.
     */
    public function formData(): JsonResponse
    {
        return ApiResponse::success([
            'departments' => Department::where('inactive', false)
                ->orderBy('name')->get(['id', 'code', 'name']),
            'job_titles' => JobTitle::where('inactive', false)
                ->orderBy('name')->get(['id', 'code', 'name']),
            'managers' => Employee::whereIn('status', ['active', 'on_leave'])
                ->orderBy('first_name')->orderBy('last_name')
                ->get(['id', 'emp_no', 'first_name', 'last_name']),
            'enums' => [
                'employment_type' => ['permanent', 'contract', 'casual', 'intern'],
                'status' => ['active', 'on_leave', 'suspended', 'terminated', 'inactive'],
                'payment_method' => ['bank', 'mpesa', 'cash'],
                'gender' => ['Male', 'Female', 'Other'],
            ],
            'next_emp_no' => $this->service->nextEmpNo(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->service->list($request->only([
                'search', 'department_id', 'job_title_id', 'status', 'employment_type',
            ]))
        );
    }

    public function show(int $id): JsonResponse
    {
        $employee = $this->service->find($id);

        return $employee
            ? ApiResponse::success($employee)
            : ApiResponse::notFound('Employee not found');
    }

    public function store(EmployeeRequest $request): JsonResponse
    {
        return ApiResponse::created(
            $this->service->create($request->validated()),
            'Employee created'
        );
    }

    public function update(EmployeeRequest $request, int $id): JsonResponse
    {
        $employee = $this->service->update($id, $request->validated());

        return $employee
            ? ApiResponse::updated($employee, 'Employee updated')
            : ApiResponse::notFound('Employee not found');
    }

    public function destroy(int $id): JsonResponse
    {
        return $this->service->deactivate($id)
            ? ApiResponse::deleted('Employee deactivated')
            : ApiResponse::notFound('Employee not found');
    }
}
