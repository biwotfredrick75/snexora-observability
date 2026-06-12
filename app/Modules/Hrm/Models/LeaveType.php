<?php

namespace App\Modules\Hrm\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    protected $table = 'hrm_leave_types';

    protected $fillable = [
        'code', 'name', 'description', 'days_per_year',
        'is_paid', 'requires_approval', 'allow_carry_forward',
        'max_carry_forward_days', 'color', 'inactive',
    ];

    protected $casts = [
        'days_per_year'          => 'decimal:1',
        'max_carry_forward_days' => 'decimal:1',
        'is_paid'                => 'boolean',
        'requires_approval'      => 'boolean',
        'allow_carry_forward'    => 'boolean',
        'inactive'               => 'boolean',
    ];
}
