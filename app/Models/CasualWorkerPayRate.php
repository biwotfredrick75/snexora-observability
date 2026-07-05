<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CasualWorkerPayRate extends Model
{
    protected $fillable = [
        'worker_id', 'trade_id', 'rate_type', 'rate',
        'overtime_multiplier', 'transport_allowance', 'meal_allowance', 'effective_from',
    ];

    protected $casts = [
        'rate'                 => 'float',
        'overtime_multiplier'  => 'float',
        'transport_allowance'  => 'float',
        'meal_allowance'       => 'float',
        'effective_from'       => 'date',
    ];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(CasualWorker::class, 'worker_id');
    }

    public function trade(): BelongsTo
    {
        return $this->belongsTo(CasualWorkerTrade::class, 'trade_id');
    }
}
