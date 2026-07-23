<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsumableIssue extends Model
{
    protected $fillable = [
        'reference', 'from_location_id', 'to_location_id', 'vehicle', 'shift', 'date',
        'dimension_id', 'dimension2_id', 'reason_id', 'gl_account', 'memo',
        'status', 'created_by', 'finance_approved_by', 'finance_approved_at', 'approved_by',
    ];

    protected $casts = [
        'finance_approved_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ConsumableIssueItem::class, 'issue_id');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(AdjustmentReason::class, 'reason_id');
    }
}
