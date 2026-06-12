<?php

namespace App\Modules\Hrm\Repositories;

use App\Modules\Hrm\Models\Employee;
use App\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class EmployeeRepository extends BaseRepository
{
    protected function getModel(): Model
    {
        return new Employee;
    }

    /**
     * Filtered employee list. Returns the full result set —
     * the frontend paginates client-side (usePagination).
     */
    public function search(array $filters): Collection
    {
        $query = Employee::query()->with([
            'department:id,code,name',
            'jobTitle:id,code,name',
            'manager:id,emp_no,first_name,last_name',
        ]);

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($w) use ($term) {
                $w->where('emp_no', 'like', "%{$term}%")
                    ->orWhere('first_name', 'like', "%{$term}%")
                    ->orWhere('middle_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('national_id', 'like', "%{$term}%")
                    ->orWhere('kra_pin', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            });
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }
        if (! empty($filters['job_title_id'])) {
            $query->where('job_title_id', $filters['job_title_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['employment_type'])) {
            $query->where('employment_type', $filters['employment_type']);
        }

        return $query->orderBy('first_name')->orderBy('last_name')->get();
    }

    /**
     * Next sequential employee number (EMP-0001, EMP-0002, …).
     */
    public function nextEmpNo(): string
    {
        $last = Employee::orderByDesc('id')->value('emp_no');
        $n = 1;

        if ($last && preg_match('/(\d+)\s*$/', $last, $m)) {
            $n = (int) $m[1] + 1;
        }

        return 'EMP-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
