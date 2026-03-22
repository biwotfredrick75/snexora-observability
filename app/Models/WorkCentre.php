<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkCentre extends Model
{
    protected $fillable = [
        'code', 'name', 'description',
        'rate_per_hour', 'overhead_rate', 'gl_account', 'inactive',
    ];

    protected $casts = [
        'rate_per_hour'  => 'float',
        'overhead_rate'  => 'float',
        'inactive'       => 'boolean',
    ];

    public function workOrders()
    {
        return $this->hasMany(WorkOrder::class);
    }
}
