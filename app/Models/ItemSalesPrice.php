<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemSalesPrice extends Model
{
    protected $fillable = ['stock_id', 'currency', 'sales_type', 'price'];

    protected $casts = ['price' => 'decimal:4'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
