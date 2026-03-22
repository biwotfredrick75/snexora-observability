<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreAllocation extends Model
{
    protected $fillable = ['user_id', 'store', 'activity', 'inactive'];
    protected $casts    = ['inactive' => 'boolean'];
}
