<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\MilkCollectionShift;

class MilkLocationTransfer extends Model
{
    protected $fillable = [
        'from_location_id', 'to_location_id', 'transfer_date',
        'shift_id', 'quantity', 'quality_notes', 'status', 'created_by',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'quantity'      => 'float',
    ];

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'to_location_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(MilkCollectionShift::class, 'shift_id');
    }
}
