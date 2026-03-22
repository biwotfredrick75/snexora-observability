<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebtorAllocation extends Model
{
    protected $fillable = [
        'debtor_no', 'source_type', 'source_id', 'source_no',
        'inv_id', 'inv_no', 'amount', 'allocated_date', 'created_by',
    ];

    protected $casts = [
        'allocated_date' => 'date',
        'amount'         => 'float',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'inv_id');
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class, 'source_id');
    }
}
