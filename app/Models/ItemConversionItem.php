<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemConversionItem extends Model
{
    protected $table    = 'item_conversion_items';
    protected $fillable = ['stock_id', 'component_id', 'quantity_produced'];
    protected $casts    = ['quantity_produced' => 'float'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'stock_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'component_id');
    }
}
