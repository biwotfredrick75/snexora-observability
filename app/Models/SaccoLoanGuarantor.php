<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @deprecated Superseded by the standalone nexora-sacco Go service (see App\Services\Sacco\SaccoServiceClient). Kept readable for the one-time legacy-data export (php artisan sacco:export-legacy) and historical reference only -- SaccoController no longer writes here.
 */
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
