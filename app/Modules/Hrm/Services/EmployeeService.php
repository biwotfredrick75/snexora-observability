<?php

namespace App\Modules\Hrm\Services;

use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Repositories\EmployeeRepository;
use Illuminate\Database\Eloquent\Collection;

class EmployeeService
{
    public function __construct(private EmployeeRepository $employees) {}

    public function list(array $filters): Collection
    {
        return $this->employees->search($filters);
    }

    public function find(int $id): ?Employee
    {
        return Employee::with(['department', 'jobTitle', 'manager'])->find($id);
    }

    public function nextEmpNo(): string
    {
        return $this->employees->nextEmpNo();
    }

    public function create(array $data): Employee
    {
        if (empty($data['emp_no'])) {
            $data['emp_no'] = $this->employees->nextEmpNo();
        }

        $employee = Employee::create($data);

        return $employee->load(['department', 'jobTitle', 'manager']);
    }

    public function update(int $id, array $data): ?Employee
    {
        $employee = Employee::find($id);

        if (! $employee) {
            return null;
        }

        // emp_no is immutable once assigned
        unset($data['emp_no']);

        $employee->update($data);

        return $employee->fresh(['department', 'jobTitle', 'manager']);
    }

    /**
     * Soft-deactivate — HR records are never hard-deleted
     * (payroll history and audit trails reference them).
     */
    public function deactivate(int $id): bool
    {
        $employee = Employee::find($id);

        if (! $employee) {
            return false;
        }

        $employee->update(['status' => 'inactive']);

        return true;
    }
}
