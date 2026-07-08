<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceReview extends Model
{
    protected $fillable = [
        'employee_id', 'reviewer_id', 'period', 'rating', 'comments',
        'status', 'submitted_at', 'acknowledged_at',
    ];

    protected $casts = [
        'rating'          => 'integer',
        'submitted_at'    => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewer_id');
    }
}
