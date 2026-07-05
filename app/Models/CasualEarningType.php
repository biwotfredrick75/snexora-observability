<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CasualEarningType extends Model
{
    protected $fillable = [
        'name', 'category', 'frequency', 'default_amount',
        'welfare_only', 'active', 'sort_order', 'gl_account_id',
    ];

    protected $casts = [
        'default_amount' => 'float',
        'welfare_only'   => 'boolean',
        'active'         => 'boolean',
    ];

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }

    public function postings(): HasMany
    {
        return $this->hasMany(CasualPayPosting::class, 'earning_type_id');
    }
}
