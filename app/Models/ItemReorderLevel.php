<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemReorderLevel extends Model
{
    protected $fillable = ['stock_id', 'location_id', 'reorder_level'];
    protected $casts    = ['reorder_level' => 'float'];

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'stock_id');
    }
}
