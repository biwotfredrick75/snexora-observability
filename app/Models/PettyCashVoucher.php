<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashVoucher extends Model
{
    protected $fillable = [
        'voucher_no', 'fund_id', 'voucher_date', 'payee', 'expense_account_code',
        'description', 'amount', 'approval_tier', 'status', 'receipt_path',
        'created_by', 'approved_by', 'approved_at', 'approval_notes',
        'rejected_by', 'rejected_at', 'rejection_reason',
        'voided_by', 'voided_at', 'void_reason', 'gl_trans_no', 'replenished',
    ];

    protected $casts = [
        'amount'       => 'float',
        'approved_at'  => 'datetime',
        'rejected_at'  => 'datetime',
        'voided_at'    => 'datetime',
        'replenished'  => 'boolean',
    ];

    public function fund(): BelongsTo
    {
        return $this->belongsTo(PettyCashFund::class, 'fund_id');
    }

    public static function tierFor(float $amount): int
    {
        if ($amount <= 50)  return 1;
        if ($amount <= 200) return 2;
        if ($amount <= 500) return 3;
        return 4;
    }

    public static function tierLabel(int $tier): string
    {
        return match($tier) {
            1 => 'Tier 1 — Self Approved',
            2 => 'Tier 2 — Supervisor',
            3 => 'Tier 3 — Finance Manager',
            4 => 'Tier 4 — CFO/Director',
            default => "Tier {$tier}",
        };
    }
}
