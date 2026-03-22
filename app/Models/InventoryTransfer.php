<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryTransfer extends Model
{
    protected $fillable = [
        'reference', 'from_location_id', 'to_location_id', 'date',
        'vehicle', 'shift', 'dimension_id', 'dimension2_id',
        'gl_account', 'memo', 'status', 'created_by', 'approved_by',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(InventoryTransferItem::class, 'transfer_id');
    }
}
