<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaccoLoanGuarantor extends Model
{
    protected $fillable = [
        'loan_id', 'member_id', 'guaranteed_amount', 'status',
    ];

    protected $casts = [
        'guaranteed_amount' => 'decimal:2',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(SaccoLoan::class, 'loan_id');
    }

    /** The guarantor — a different SaccoMember than the loan's own borrower. */
    public function guarantor(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }
}
