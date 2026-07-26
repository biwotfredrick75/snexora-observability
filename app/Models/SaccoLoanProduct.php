<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @deprecated Superseded by the standalone nexora-sacco Go service (see App\Services\Sacco\SaccoServiceClient). Kept readable for the one-time legacy-data export (php artisan sacco:export-legacy) and historical reference only -- SaccoController no longer writes here.
 */
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
