<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockRequisitionItem extends Model
{
    protected $fillable = ['requisition_id', 'stock_id', 'quantity', 'unit'];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(StockRequisition::class, 'requisition_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'stock_id', 'stock_id');
    }
}
