<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MilkCollectionShift extends Model
{
    protected $fillable = ['description', 'start_time', 'end_time', 'active'];

    protected $casts = ['active' => 'boolean'];
}
