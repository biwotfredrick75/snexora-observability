<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $table = 'hrm_departments';

    protected $fillable = [
        'code', 'name', 'description', 'inactive',
    ];

    protected $casts = [
        'inactive' => 'boolean',
    ];

    protected $appends = ['employees_count'];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function getEmployeesCountAttribute(): int
    {
        // Use loaded relation count when eager-loaded (withCount), otherwise query.
        if (array_key_exists('employees_count', $this->attributes)) {
            return (int) $this->attributes['employees_count'];
        }

        return $this->employees()->count();
    }
}
