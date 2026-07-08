<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceGoal extends Model
{
    protected $fillable = [
        'employee_id', 'title', 'description', 'due_date', 'progress', 'status',
    ];

    protected $casts = [
        'due_date' => 'date',
        'progress' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
