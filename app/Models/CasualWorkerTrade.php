<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CasualWorkerTrade extends Model
{
    protected $fillable = ['name', 'default_daily_rate', 'sort_order', 'pay_welfare'];

    protected $casts = [
        'default_daily_rate' => 'float',
        'pay_welfare'        => 'boolean',
    ];

    public function workers(): HasMany
    {
        return $this->hasMany(CasualWorker::class, 'trade_id');
    }

    public function payRates(): HasMany
    {
        return $this->hasMany(CasualWorkerPayRate::class, 'trade_id');
    }
}
