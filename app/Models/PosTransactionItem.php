<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosTransactionItem extends Model
{
    protected $fillable = [
        'pos_transaction_id', 'stock_id', 'description',
        'unit_price', 'quantity', 'discount_percent',
        'tax_rate', 'tax_amount', 'line_total',
    ];

    protected $casts = [
        'unit_price'       => 'float',
        'quantity'         => 'float',
        'discount_percent' => 'float',
        'tax_rate'         => 'float',
        'tax_amount'       => 'float',
        'line_total'       => 'float',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PosTransaction::class, 'pos_transaction_id');
    }
}
