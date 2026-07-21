<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MilkPurchaseItem extends Model
{
    protected $fillable = [
        'purchase_id', 'farmer_id', 'quantity',
        'unit_price', 'total_price', 'unique_key',
        'latitude', 'longitude', 'gps_accuracy', 'scale_connected', 'scale_device', 'captured_at',
        'smell_result', 'alcohol_test_result', 'density', 'butterfat_percent', 'adulteration_result',
        'quality_status', 'rejection_reason', 'tested_by', 'tested_at',
    ];

    protected $casts = [
        'quantity'          => 'float',
        'unit_price'        => 'float',
        'total_price'       => 'float',
        'latitude'          => 'float',
        'longitude'         => 'float',
        'gps_accuracy'      => 'float',
        'scale_connected'   => 'boolean',
        'captured_at'       => 'datetime',
        'density'           => 'float',
        'butterfat_percent' => 'float',
        'tested_at'         => 'datetime',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(MilkPurchase::class, 'purchase_id');
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class, 'farmer_id');
    }
}
