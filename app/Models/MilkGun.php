<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MilkGun extends Model
{
    protected $fillable = ['route_id', 'grader', 'gun_no', 'capacity', 'status'];

    protected $casts = [
        'capacity' => 'decimal:2',
    ];
}
