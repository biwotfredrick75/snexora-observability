<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    protected $fillable = [
        'code', 'name', 'default_days_per_year', 'is_paid', 'inactive',
    ];

    protected $casts = [
        'default_days_per_year' => 'integer',
        'is_paid'               => 'boolean',
        'inactive'              => 'boolean',
    ];

    public function requests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
