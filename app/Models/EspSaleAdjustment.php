<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EspSaleAdjustment extends Model
{
    protected $table = 'esp_sale_adjustments';

    protected $fillable = ['sale_id', 'delta_amount', 'reason', 'created_by'];

    protected $casts = [
        'delta_amount' => 'decimal:2',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(EspSale::class, 'sale_id');
    }
}
