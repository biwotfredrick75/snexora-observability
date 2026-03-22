<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsumableIssue extends Model
{
    protected $fillable = [
        'reference', 'from_location_id', 'vehicle', 'shift', 'date',
        'dimension_id', 'dimension2_id', 'gl_account', 'memo',
        'status', 'created_by', 'approved_by',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ConsumableIssueItem::class, 'issue_id');
    }
}
