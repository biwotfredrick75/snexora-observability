<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrChecklistProcess extends Model
{
    protected $fillable = [
        'employee_id', 'type', 'status', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(HrChecklistTask::class, 'process_id')->orderBy('sort_order');
    }
}
