<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CasualPayPosting extends Model
{
    protected $fillable = ['pay_run_id', 'worker_id', 'earning_type_id', 'amount', 'note', 'is_auto'];

    protected $casts = [
        'amount'  => 'float',
        'is_auto' => 'boolean',
    ];

    public function earningType(): BelongsTo
    {
        return $this->belongsTo(CasualEarningType::class, 'earning_type_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(CasualWorker::class, 'worker_id');
    }
}
