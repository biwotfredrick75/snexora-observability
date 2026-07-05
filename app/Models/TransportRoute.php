<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransportRoute extends Model
{
    protected $fillable = [
        'route_code', 'route_name', 'description', 'status',
    ];

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'route_id');
    }

    public function loadingOrders(): HasMany
    {
        return $this->hasMany(LoadingOrder::class, 'route_id');
    }
}
