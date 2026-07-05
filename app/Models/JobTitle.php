<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobTitle extends Model
{
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
        if (array_key_exists('employees_count', $this->attributes)) {
            return (int) $this->attributes['employees_count'];
        }

        return $this->employees()->count();
    }
}
