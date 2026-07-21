<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaccoTransaction extends Model
{
    protected $fillable = [
        'account_id', 'type', 'amount', 'reference', 'transaction_date',
        'narration', 'source', 'created_by',
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'transaction_date' => 'date',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(SaccoAccount::class, 'account_id');
    }
}
