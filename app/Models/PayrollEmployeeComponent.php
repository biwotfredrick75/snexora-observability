<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollEmployeeComponent extends Model
{
    protected $fillable = [
        'employee_id', 'component_id', 'amount', 'is_recurring',
        'start_date', 'end_date', 'total_installments', 'installments_used',
        'active', 'notes', 'created_by',
    ];

    protected $casts = [
        'amount'             => 'decimal:2',
        'is_recurring'       => 'boolean',
        'start_date'         => 'date',
        'end_date'           => 'date',
        'total_installments' => 'integer',
        'installments_used'  => 'integer',
        'active'             => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayrollPayComponent::class, 'component_id');
    }

    /** Eligible for a given period: active, within date range, installments not exhausted. */
    public function scopeEligibleFor($query, \DateTimeInterface|string $periodStart, \DateTimeInterface|string $periodEnd)
    {
        return $query->where('active', true)
            ->where('start_date', '<=', $periodEnd)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $periodStart))
            ->where(fn ($q) => $q->whereNull('total_installments')->orWhereColumn('installments_used', '<', 'total_installments'));
    }
}
