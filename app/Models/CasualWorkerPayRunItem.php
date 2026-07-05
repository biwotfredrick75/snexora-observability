<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CasualWorkerPayRunItem extends Model
{
    protected $fillable = [
        'pay_run_id', 'worker_id', 'days_worked', 'hours_worked',
        'base_rate', 'base_pay', 'transport_allowance', 'meal_allowance',
        'other_allowances', 'manual_allowances', 'deductions', 'manual_deductions',
        'net_pay', 'payment_status', 'payment_ref',
    ];

    protected $casts = [
        'days_worked'         => 'float',
        'hours_worked'        => 'float',
        'base_rate'           => 'float',
        'base_pay'            => 'float',
        'transport_allowance' => 'float',
        'meal_allowance'      => 'float',
        'other_allowances'    => 'float',
        'manual_allowances'   => 'float',
        'deductions'          => 'float',
        'manual_deductions'   => 'float',
        'net_pay'             => 'float',
    ];

    public function payRun(): BelongsTo
    {
        return $this->belongsTo(CasualWorkerPayRun::class, 'pay_run_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(CasualWorker::class, 'worker_id');
    }
}
