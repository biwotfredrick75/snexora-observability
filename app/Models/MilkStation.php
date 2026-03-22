<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MilkStation extends Model
{
    protected $fillable = ['route_id', 'station_name', 'status'];
}
