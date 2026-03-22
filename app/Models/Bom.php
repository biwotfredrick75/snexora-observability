<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bom extends Model
{
    protected $table = 'boms';

    protected $fillable = [
        'bom_no', 'product_code', 'description', 'version',
        'standard_batch_qty', 'batch_unit', 'is_active', 'created_by',
    ];

    protected $casts = [
        'standard_batch_qty' => 'float',
        'is_active'          => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(BomItem::class)->orderBy('sort_order');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }
}
