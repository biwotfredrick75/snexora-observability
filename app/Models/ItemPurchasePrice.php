<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemPurchasePrice extends Model
{
    protected $fillable = [
        'stock_id', 'supplier', 'price', 'currency',
        'supplier_uom', 'conversion_factor', 'supplier_description',
    ];

    protected $casts = [
        'price'             => 'decimal:4',
        'conversion_factor' => 'decimal:4',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
