<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GraderDeduction extends Model
{
    protected $fillable = [
        'grader_id', 'deduction_date', 'amount', 'reason',
        'source_type', 'source_ref', 'settled', 'settled_at', 'settled_ref',
        'created_by',
    ];

    protected $casts = [
        'deduction_date' => 'date',
        'amount'         => 'float',
        'settled'        => 'boolean',
        'settled_at'     => 'date',
    ];

    public function grader(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'grader_id');
    }
}
