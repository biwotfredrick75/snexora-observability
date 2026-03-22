<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BomItem extends Model
{
    protected $fillable = [
        'bom_id', 'component_code', 'description',
        'qty_required', 'unit', 'waste_pct', 'sort_order',
    ];

    protected $casts = [
        'qty_required' => 'float',
        'waste_pct'    => 'float',
        'sort_order'   => 'integer',
    ];

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }
}
