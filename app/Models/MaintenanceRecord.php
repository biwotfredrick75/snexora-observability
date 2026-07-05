<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceRecord extends Model
{
    protected $fillable = [
        'vehicle_id', 'service_date', 'description', 'cost', 'next_service_due', 'status',
    ];

    protected $casts = [
        'vehicle_id'        => 'integer',
        'service_date'      => 'date',
        'cost'              => 'decimal:2',
        'next_service_due'  => 'date',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
