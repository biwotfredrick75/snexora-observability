<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilkTransferReception extends Model
{
    protected $fillable = [
        'trip_ref', 'to_location_id', 'transporter_id', 'dispatched_qty',
        'received_gross_weight', 'received_tare_weight', 'received_net_weight',
        'temperature_c',
        'smell_result', 'alcohol_test_result', 'density', 'butterfat_percent',
        'adulteration_result', 'quality_status', 'rejection_reason',
        'received_qty', 'accepted_qty', 'rejected_qty', 'shortage_qty',
        'deduction_amount', 'received_by', 'received_at',
    ];

    protected $casts = [
        'dispatched_qty'         => 'float',
        'received_gross_weight'  => 'float',
        'received_tare_weight'   => 'float',
        'received_net_weight'    => 'float',
        'temperature_c'          => 'float',
        'density'                => 'float',
        'butterfat_percent'      => 'float',
        'received_qty'           => 'float',
        'accepted_qty'           => 'float',
        'rejected_qty'           => 'float',
        'shortage_qty'           => 'float',
        'deduction_amount'       => 'float',
        'received_at'            => 'datetime',
    ];

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'to_location_id');
    }

    public function transporter(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'transporter_id');
    }
}
