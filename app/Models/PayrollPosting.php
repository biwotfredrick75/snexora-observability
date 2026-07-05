<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollPosting extends Model
{
    protected $fillable = [
        'payroll_period_id', 'employee_id', 'component_id', 'amount', 'note', 'is_auto',
    ];

    protected $casts = [
        'amount'  => 'decimal:2',
        'is_auto' => 'boolean',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayrollPayComponent::class, 'component_id');
    }
}
