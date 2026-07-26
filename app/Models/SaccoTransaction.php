<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @deprecated Superseded by the standalone nexora-sacco Go service (see App\Services\Sacco\SaccoServiceClient). Kept readable for the one-time legacy-data export (php artisan sacco:export-legacy) and historical reference only -- SaccoController no longer writes here.
 */
class SaccoTransaction extends Model
{
    protected $fillable = [
        'account_id', 'type', 'amount', 'reference', 'transaction_date',
        'narration', 'source', 'created_by',
    ];

    protected $casts = [
        'amount'           => 'decimal:2',
        'transaction_date' => 'date',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(SaccoAccount::class, 'account_id');
    }
}
