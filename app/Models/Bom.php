<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bom extends Model
{
    protected $table = 'boms';

    protected $fillable = [
        'bom_no', 'product_code', 'description', 'version',
        'standard_batch_qty', 'batch_unit', 'scrap_pct', 'target_margin_pct', 'mfg_type_id', 'is_active', 'created_by',
    ];

    protected $casts = [
        'standard_batch_qty' => 'float',
        'scrap_pct'          => 'float',
        'target_margin_pct'  => 'float',
        'is_active'          => 'boolean',
    ];

    public function mfgType(): BelongsTo
    {
        return $this->belongsTo(ManufacturingType::class, 'mfg_type_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BomItem::class)->orderBy('sort_order');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }
}
