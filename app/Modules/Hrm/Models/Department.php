<?php

namespace App\Modules\Hrm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $table = 'hrm_departments';

    protected $fillable = [
        'code', 'name', 'description', 'head_employee_id', 'inactive',
    ];

    protected $casts = [
        'inactive' => 'boolean',
        'head_employee_id' => 'integer',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'department_id');
    }
}
