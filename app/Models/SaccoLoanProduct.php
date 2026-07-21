<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaccoLoanProduct extends Model
{
    protected $fillable = [
        'name', 'interest_rate_pct', 'max_term_months', 'max_savings_multiplier', 'status',
    ];

    protected $casts = [
        'interest_rate_pct'      => 'float',
        'max_savings_multiplier' => 'float',
    ];

    public function loans(): HasMany
    {
        return $this->hasMany(SaccoLoan::class, 'product_id');
    }
}
