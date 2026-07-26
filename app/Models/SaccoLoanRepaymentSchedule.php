<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @deprecated Superseded by the standalone nexora-sacco Go service (see App\Services\Sacco\SaccoServiceClient). Kept readable for the one-time legacy-data export (php artisan sacco:export-legacy) and historical reference only -- SaccoController no longer writes here.
 */
class SaccoLoanRepaymentSchedule extends Model
{
    protected $table = 'sacco_loan_repayment_schedule';

    protected $fillable = [
        'loan_id', 'installment_no', 'due_date', 'principal_due', 'interest_due',
        'total_due', 'amount_paid', 'status', 'paid_via', 'paid_date', 'checkoff_posted_at',
    ];

    protected $casts = [
        'due_date'           => 'date',
        'paid_date'          => 'date',
        'checkoff_posted_at' => 'datetime',
        'principal_due'      => 'decimal:2',
        'interest_due'       => 'decimal:2',
        'total_due'          => 'decimal:2',
        'amount_paid'        => 'decimal:2',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(SaccoLoan::class, 'loan_id');
    }
}
