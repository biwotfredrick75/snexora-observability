<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashReconciliation extends Model
{
    protected $fillable = [
        'recon_no', 'fund_id', 'recon_date', 'expected_balance', 'vouchers_total',
        'cash_counted', 'variance', 'is_surprise_audit', 'status', 'created_by',
        'custodian_signed_at', 'supervisor_id', 'supervisor_signed_at',
        'variance_gl_trans_no', 'notes',
    ];

    protected $casts = [
        'expected_balance'      => 'float',
        'vouchers_total'        => 'float',
        'cash_counted'          => 'float',
        'variance'              => 'float',
        'is_surprise_audit'     => 'boolean',
        'custodian_signed_at'   => 'datetime',
        'supervisor_signed_at'  => 'datetime',
    ];

    public function fund(): BelongsTo
    {
        return $this->belongsTo(PettyCashFund::class, 'fund_id');
    }
}
