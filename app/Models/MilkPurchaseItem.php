<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilkPurchaseItem extends Model
{
    protected $fillable = [
        'purchase_id', 'farmer_id', 'quantity',
        'unit_price', 'total_price', 'unique_key',
    ];

    protected $casts = [
        'quantity'    => 'float',
        'unit_price'  => 'float',
        'total_price' => 'float',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(MilkPurchase::class, 'purchase_id');
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class, 'farmer_id');
    }
}
