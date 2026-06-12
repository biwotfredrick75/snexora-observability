<?php

namespace App\Modules\Hrm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveBalance extends Model
{
    protected $table = 'hrm_leave_balances';

    protected $fillable = [
        'employee_id', 'leave_type_id', 'year',
        'entitled_days', 'carried_forward_days', 'taken_days', 'pending_days',
    ];

    protected $casts = [
        'year'                 => 'integer',
        'entitled_days'        => 'decimal:1',
        'carried_forward_days' => 'decimal:1',
        'taken_days'           => 'decimal:1',
        'pending_days'         => 'decimal:1',
    ];

    protected $appends = ['available_days'];

    /**
     * Days the employee can still book = entitled + carried − taken − pending.
     */
    public function getAvailableDaysAttribute(): float
    {
        return (float) $this->entitled_days
             + (float) $this->carried_forward_days
             - (float) $this->taken_days
             - (float) $this->pending_days;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
