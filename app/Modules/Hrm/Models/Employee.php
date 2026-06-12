<?php

namespace App\Modules\Hrm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
    protected $table = 'hrm_employees';

    protected $fillable = [
        'emp_no', 'first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth',
        'national_id', 'kra_pin', 'nssf_no', 'shif_no', 'nita_no', 'helb_no',
        'email', 'phone', 'physical_address',
        'department_id', 'job_title_id', 'manager_id', 'user_id',
        'employment_type', 'hire_date', 'end_date', 'status',
        'basic_salary', 'payment_method', 'bank_name', 'bank_branch', 'bank_account',
        'photo_filename', 'notes',
    ];

    protected $casts = [
        'date_of_birth' => 'date:Y-m-d',
        'hire_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'basic_salary' => 'decimal:2',
        'department_id' => 'integer',
        'job_title_id' => 'integer',
        'manager_id' => 'integer',
    ];

    protected $appends = ['full_name'];

    public function getFullNameAttribute(): string
    {
        return trim(preg_replace('/\s+/', ' ', "{$this->first_name} {$this->middle_name} {$this->last_name}"));
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class, 'job_title_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }
}
