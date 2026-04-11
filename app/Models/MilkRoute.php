<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MilkRoute extends Model
{
    protected $fillable = [
        'route_code', 'route_name', 'location_id', 'merged_route_ids', 'sessions', 'status',
    ];

    protected $casts = [
        'merged_route_ids' => 'array',
        'sessions'         => 'array',
    ];

    public function farmers(): HasMany
    {
        return $this->hasMany(Farmer::class, 'route_id');
    }
}
