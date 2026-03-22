<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesKitItem extends Model
{
    protected $table    = 'sales_kit_items';
    protected $fillable = ['kit_id', 'alias_code', 'component_id', 'quantity', 'uom'];
    protected $casts    = ['quantity' => 'float'];

    public function kit(): BelongsTo
    {
        return $this->belongsTo(SalesKit::class, 'kit_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'component_id');
    }
}
