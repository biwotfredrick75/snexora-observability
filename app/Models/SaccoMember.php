<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @deprecated Superseded by the standalone nexora-sacco Go service (see App\Services\Sacco\SaccoServiceClient). Kept readable for the one-time legacy-data export (php artisan sacco:export-legacy) and historical reference only -- SaccoController no longer writes here.
 */
class SaccoMember extends Model
{
    protected $fillable = [
        'membership_no', 'farmer_id', 'join_date', 'status', 'created_by',
    ];

    protected $casts = [
        'join_date' => 'date',
    ];

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class, 'farmer_id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(SaccoAccount::class, 'member_id');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(SaccoLoan::class, 'member_id');
    }

    public function savingsAccount(): ?SaccoAccount
    {
        return $this->accounts->firstWhere('account_type', 'savings');
    }

    public function sharesAccount(): ?SaccoAccount
    {
        return $this->accounts->firstWhere('account_type', 'shares');
    }
}
