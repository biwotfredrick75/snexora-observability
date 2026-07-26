<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @deprecated Superseded by the standalone nexora-sacco Go service (see App\Services\Sacco\SaccoServiceClient). Kept readable for the one-time legacy-data export (php artisan sacco:export-legacy) and historical reference only -- SaccoController no longer writes here.
 */
class SaccoLoan extends Model
{
    protected $fillable = [
        'loan_no', 'member_id', 'product_id', 'principal_amount', 'interest_rate_pct',
        'term_months', 'application_date', 'disbursement_date', 'status',
        'outstanding_balance', 'approved_by', 'disbursed_by', 'created_by',
    ];

    protected $casts = [
        'application_date'     => 'date',
        'disbursement_date'    => 'date',
        'principal_amount'     => 'decimal:2',
        'interest_rate_pct'    => 'float',
        'outstanding_balance'  => 'decimal:2',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SaccoLoanProduct::class, 'product_id');
    }

    public function guarantors(): HasMany
    {
        return $this->hasMany(SaccoLoanGuarantor::class, 'loan_id');
    }

    public function schedule(): HasMany
    {
        return $this->hasMany(SaccoLoanRepaymentSchedule::class, 'loan_id')->orderBy('installment_no');
    }
}
