<?php

namespace App\Http\Controllers\Hrm;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobTitle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    private const EMPLOYMENT_TYPES = ['permanent', 'contract', 'casual', 'intern'];
    private const STATUSES = ['active', 'on_leave', 'suspended', 'terminated', 'inactive'];
    private const PAYMENT_METHODS = ['bank', 'mpesa', 'cash'];
    private const GENDERS = ['Male', 'Female', 'Other'];

    public function index(): JsonResponse
    {
        $employees = Employee::with(['department', 'jobTitle'])
            ->orderBy('full_name')
            ->get();

        return ApiResponse::success($employees, 'Employees retrieved');
    }

    /**
     * Dropdown / lookup data + next employee number preview for the
     * Employee create/edit form (EmployeeForm.vue).
     */
    public function formData(): JsonResponse
    {
        return ApiResponse::success([
            'departments' => Department::where('inactive', false)->orderBy('name')->get(['id', 'name']),
            'job_titles'  => JobTitle::where('inactive', false)->orderBy('name')->get(['id', 'name']),
            'managers'    => Employee::where('status', '!=', 'terminated')
                ->orderBy('first_name')
                ->get(['id', 'emp_no', 'first_name', 'last_name']),
            'enums' => [
                'employment_type' => self::EMPLOYMENT_TYPES,
                'status'          => self::STATUSES,
                'payment_method'  => self::PAYMENT_METHODS,
                'gender'          => self::GENDERS,
            ],
            'next_emp_no' => $this->previewNextEmpNo(),
        ], 'Employee form data retrieved');
    }

    public function show(int $id): JsonResponse
    {
        $employee = Employee::with(['department', 'jobTitle', 'manager'])->find($id);

        if (! $employee) {
            return ApiResponse::notFound('Employee not found');
        }

        return ApiResponse::success($employee, 'Employee retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $data['emp_no'] = $this->nextEmpNo();
        $data['full_name'] = $this->buildFullName($data);

        $employee = Employee::create($data);

        return ApiResponse::created($employee->load(['department', 'jobTitle']), 'Employee created');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $employee = Employee::find($id);

        if (! $employee) {
            return ApiResponse::notFound('Employee not found');
        }

        $rules = $this->rules($id);
        unset($rules['emp_no']); // employee number is system-assigned, never edited

        $data = $request->validate($rules);
        $data['full_name'] = $this->buildFullName([
            'first_name'  => $data['first_name']  ?? $employee->first_name,
            'middle_name' => $data['middle_name'] ?? $employee->middle_name,
            'last_name'   => $data['last_name']   ?? $employee->last_name,
        ]);

        $employee->fill($data)->save();

        return ApiResponse::updated($employee->fresh(['department', 'jobTitle']), 'Employee updated');
    }

    /**
     * Soft-delete: deactivate rather than hard-delete, since employee
     * records are referenced by payroll/leave/other modules.
     */
    public function destroy(int $id): JsonResponse
    {
        $employee = Employee::find($id);

        if (! $employee) {
            return ApiResponse::notFound('Employee not found');
        }

        $employee->update(['status' => 'inactive']);

        return ApiResponse::deleted('Employee deactivated');
    }

    private function rules(?int $id = null): array
    {
        return [
            'emp_no'           => 'sometimes|string|max:30',
            'first_name'       => 'required|string|max:80',
            'middle_name'      => 'nullable|string|max:80',
            'last_name'        => 'required|string|max:80',
            'gender'           => 'nullable|in:' . implode(',', self::GENDERS),
            'date_of_birth'    => 'nullable|date',
            'national_id'      => 'nullable|string|max:30',
            'kra_pin'          => 'nullable|string|max:20',
            'nssf_no'          => 'nullable|string|max:30',
            'shif_no'          => 'nullable|string|max:30',
            'nita_no'          => 'nullable|string|max:30',
            'helb_no'          => 'nullable|string|max:30',
            'email'            => 'nullable|email|max:150',
            'phone'            => 'nullable|string|max:30',
            'physical_address' => 'nullable|string|max:255',
            'department_id'    => 'nullable|integer|exists:departments,id',
            'job_title_id'     => 'nullable|integer|exists:job_titles,id',
            'manager_id'       => 'nullable|integer|exists:employees,id',
            'user_id'          => 'nullable|string|max:60',
            'employment_type'  => 'required|in:' . implode(',', self::EMPLOYMENT_TYPES),
            'hire_date'        => 'nullable|date',
            'end_date'         => 'nullable|date',
            'status'           => 'required|in:' . implode(',', self::STATUSES),
            'basic_salary'     => 'nullable|numeric|min:0',
            'payment_method'   => 'required|in:' . implode(',', self::PAYMENT_METHODS),
            'bank_name'        => 'nullable|string|max:100',
            'bank_branch'      => 'nullable|string|max:100',
            'bank_account'     => 'nullable|string|max:50',
            'notes'            => 'nullable|string',
        ];
    }

    private function buildFullName(array $data): string
    {
        return trim(collect([
            $data['first_name']  ?? '',
            $data['middle_name'] ?? '',
            $data['last_name']   ?? '',
        ])->filter()->implode(' '));
    }

    /**
     * Generates the next sequential employee number, e.g. EMP-0001.
     * Mirrors the ad-hoc MAX+1 convention used elsewhere in this codebase
     * (see CasualWorkerController::generateWorkerNo()) — extracts the
     * numeric suffix of the last emp_no via regex rather than relying on
     * DB-specific SUBSTRING/CAST functions.
     */
    private function nextEmpNo(): string
    {
        return sprintf('EMP-%04d', $this->nextSequence());
    }

    private function previewNextEmpNo(): string
    {
        return sprintf('EMP-%04d', $this->nextSequence());
    }

    private function nextSequence(): int
    {
        $last = Employee::orderByDesc('id')->value('emp_no');

        if ($last && preg_match('/(\d+)$/', $last, $m)) {
            return ((int) $m[1]) + 1;
        }

        return 1;
    }
}
