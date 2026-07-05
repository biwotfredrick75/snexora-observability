<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $table = 'hrm_employees';

    protected $fillable = [
        'emp_no', 'first_name', 'middle_name', 'last_name', 'full_name',
        'gender', 'date_of_birth', 'national_id',
        'kra_pin', 'nssf_no', 'shif_no', 'nita_no', 'helb_no',
        'email', 'phone', 'physical_address',
        'department_id', 'job_title_id', 'manager_id', 'user_id',
        'employment_type', 'hire_date', 'end_date', 'status',
        'basic_salary', 'payment_method', 'bank_name', 'bank_branch', 'bank_account',
        'notes',
    ];

    protected $casts = [
        'department_id' => 'integer',
        'job_title_id'  => 'integer',
        'manager_id'    => 'integer',
        'date_of_birth' => 'date',
        'hire_date'     => 'date',
        'end_date'      => 'date',
        'basic_salary'  => 'decimal:2',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    // Relation name `jobTitle` serializes as `job_title` in JSON (Laravel
    // converts camelCase relation method names to snake_case array/JSON
    // keys) — matches what EmployeeList.vue reads (`e.job_title?.name`).
    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class, 'job_title_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }

    public function payrollItems(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }
}
