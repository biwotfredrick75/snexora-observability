<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FuelEntry extends Model
{
    protected $fillable = [
        'vehicle_id', 'entry_date', 'litres', 'cost', 'odometer_reading', 'station',
    ];

    protected $casts = [
        'vehicle_id'       => 'integer',
        'entry_date'       => 'date',
        'litres'           => 'decimal:2',
        'cost'             => 'decimal:2',
        'odometer_reading' => 'integer',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
