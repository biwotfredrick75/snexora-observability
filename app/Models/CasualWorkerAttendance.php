<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CasualWorkerAttendance extends Model
{
    protected $fillable = [
        'worker_id', 'work_date', 'shift', 'status',
        'hours_worked', 'check_in', 'check_out', 'notes', 'recorded_by',
    ];

    protected $casts = [
        'work_date'    => 'date',
        'hours_worked' => 'float',
    ];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(CasualWorker::class, 'worker_id');
    }
}
