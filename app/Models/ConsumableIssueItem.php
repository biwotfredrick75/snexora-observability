<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumableIssueItem extends Model
{
    protected $fillable = ['issue_id', 'stock_id', 'quantity', 'unit'];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(ConsumableIssue::class, 'issue_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'stock_id', 'stock_id');
    }
}
