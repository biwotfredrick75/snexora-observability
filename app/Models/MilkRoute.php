<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MilkRoute extends Model
{
    protected $fillable = [
        'route_code', 'route_name', 'location_id', 'merged_route_ids', 'sessions', 'status',
    ];

    protected $casts = [
        'merged_route_ids' => 'array',
        'sessions'         => 'array',
    ];
}
