<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackagingQuantity extends Model
{
    protected $fillable = [
        'packaging_type_id', 'quantity', 'condition',
        'location_type', 'location_code', 'notes', 'inactive',
    ];
    protected $casts = ['inactive' => 'boolean', 'quantity' => 'float'];

    public function packagingType(): BelongsTo
    {
        return $this->belongsTo(PackagingType::class, 'packaging_type_id');
    }
}
