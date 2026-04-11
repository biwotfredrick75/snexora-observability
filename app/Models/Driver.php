<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Driver extends Model
{
    protected $fillable = [
        'name', 'license_no', 'license_class', 'phone', 'vehicle_id', 'inactive',
    ];

    protected $casts = [
        'vehicle_id' => 'integer',
        'inactive'   => 'boolean',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
