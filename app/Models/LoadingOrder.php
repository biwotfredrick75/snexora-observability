<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoadingOrder extends Model
{
    protected $fillable = [
        'order_no', 'route_id', 'vehicle_id', 'driver_id', 'order_date', 'status', 'notes',
    ];

    protected $casts = [
        'route_id'   => 'integer',
        'vehicle_id' => 'integer',
        'driver_id'  => 'integer',
        'order_date' => 'date',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(TransportRoute::class, 'route_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LoadingOrderItem::class, 'loading_order_id');
    }
}
