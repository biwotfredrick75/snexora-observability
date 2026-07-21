<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaccoAccount extends Model
{
    protected $fillable = [
        'member_id', 'account_type', 'account_no', 'balance', 'status',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(SaccoMember::class, 'member_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SaccoTransaction::class, 'account_id');
    }
}
