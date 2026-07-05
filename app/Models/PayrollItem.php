<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollItem extends Model
{
    protected $fillable = [
        'payroll_period_id', 'employee_id',
        'basic_salary', 'gross_pay', 'taxable_pay',
        'paye', 'shif', 'nssf', 'housing_levy',
        'other_allowances', 'manual_allowances',
        'other_deductions', 'manual_deductions',
        'net_pay', 'payment_status', 'payment_ref',
    ];

    protected $casts = [
        'basic_salary'       => 'decimal:2',
        'gross_pay'          => 'decimal:2',
        'taxable_pay'        => 'decimal:2',
        'paye'               => 'decimal:2',
        'shif'               => 'decimal:2',
        'nssf'               => 'decimal:2',
        'housing_levy'       => 'decimal:2',
        'other_allowances'   => 'decimal:2',
        'manual_allowances'  => 'decimal:2',
        'other_deductions'   => 'decimal:2',
        'manual_deductions'  => 'decimal:2',
        'net_pay'            => 'decimal:2',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
