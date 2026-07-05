<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    protected $fillable = [
        'plate_no', 'make', 'model', 'year', 'type', 'color', 'capacity', 'inactive', 'route_id',
    ];

    protected $casts = [
        'year'     => 'integer',
        'capacity' => 'integer',
        'inactive' => 'boolean',
        'route_id' => 'integer',
    ];

    // Derived display status ("Active"/"Inactive") from the `inactive` boolean —
    // avoids duplicating a separate status column. Used by Fleet Registry / Transport UI.
    protected $appends = ['status'];

    public function getStatusAttribute(): string
    {
        return $this->inactive ? 'Inactive' : 'Active';
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'route_id');
    }
}
