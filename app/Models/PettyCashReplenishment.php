<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashReplenishment extends Model
{
    protected $fillable = [
        'repl_no', 'fund_id', 'request_date', 'requested_by', 'amount_requested',
        'vouchers_count', 'bank_account_code', 'status', 'approved_by', 'approved_at',
        'payment_reference', 'payment_date', 'confirmed_by', 'confirmed_at',
        'gl_trans_no', 'notes',
    ];

    protected $casts = [
        'amount_requested' => 'float',
        'approved_at'      => 'datetime',
        'confirmed_at'     => 'datetime',
        'payment_date'     => 'date',
    ];

    public function fund(): BelongsTo
    {
        return $this->belongsTo(PettyCashFund::class, 'fund_id');
    }
}
