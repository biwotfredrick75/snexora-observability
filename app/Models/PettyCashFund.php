<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PettyCashFund extends Model
{
    protected $fillable = [
        'fund_code', 'name', 'description', 'gl_account_code',
        'imprest_amount', 'current_balance', 'transaction_limit', 'low_balance_pct',
        'custodian_user_id', 'backup_custodian_user_id', 'cost_center', 'currency', 'status',
    ];

    protected $casts = [
        'imprest_amount'   => 'float',
        'current_balance'  => 'float',
        'transaction_limit' => 'float',
        'low_balance_pct'  => 'integer',
    ];

    public function vouchers(): HasMany
    {
        return $this->hasMany(PettyCashVoucher::class, 'fund_id');
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(PettyCashReconciliation::class, 'fund_id');
    }

    public function replenishments(): HasMany
    {
        return $this->hasMany(PettyCashReplenishment::class, 'fund_id');
    }

    public function isLowBalance(): bool
    {
        if ($this->imprest_amount <= 0) return false;
        return ($this->current_balance / $this->imprest_amount * 100) <= $this->low_balance_pct;
    }
}
