<?php

namespace App\Modules\Hrm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    protected $table = 'hrm_leave_requests';

    protected $fillable = [
        'request_no', 'employee_id', 'leave_type_id',
        'start_date', 'end_date', 'days', 'reason', 'status',
        'applied_at', 'approved_by', 'approved_at', 'approval_notes',
    ];

    protected $casts = [
        'start_date'  => 'date:Y-m-d',
        'end_date'    => 'date:Y-m-d',
        'days'        => 'decimal:1',
        'applied_at'  => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
