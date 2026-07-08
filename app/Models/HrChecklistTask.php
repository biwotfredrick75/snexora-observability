<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrChecklistTask extends Model
{
    protected $fillable = [
        'process_id', 'title', 'description', 'category', 'sort_order',
        'is_done', 'done_at', 'done_by',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_done'    => 'boolean',
        'done_at'    => 'datetime',
    ];

    public function process(): BelongsTo
    {
        return $this->belongsTo(HrChecklistProcess::class, 'process_id');
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'done_by');
    }
}
