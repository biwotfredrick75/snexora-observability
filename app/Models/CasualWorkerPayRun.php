<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CasualWorkerPayRun extends Model
{
    protected $fillable = [
        'ref_no', 'period_start', 'period_end', 'status',
        'total_amount', 'notes', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end'   => 'date',
        'total_amount' => 'float',
        'approved_at'  => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(CasualWorkerPayRunItem::class, 'pay_run_id');
    }

    public function postings(): HasMany
    {
        return $this->hasMany(CasualPayPosting::class, 'pay_run_id');
    }
}
