<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockRequisition extends Model
{
    protected $fillable = [
        'reference', 'from_location_id', 'to_location_id', 'date',
        'person', 'gl_account', 'memo', 'status', 'raised_by', 'approved_by',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(StockRequisitionItem::class, 'requisition_id');
    }
}
