<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoadingOrderItem extends Model
{
    protected $fillable = [
        'loading_order_id', 'description', 'quantity', 'unit',
    ];

    protected $casts = [
        'loading_order_id' => 'integer',
        'quantity'         => 'decimal:2',
    ];

    public function loadingOrder(): BelongsTo
    {
        return $this->belongsTo(LoadingOrder::class, 'loading_order_id');
    }
}
